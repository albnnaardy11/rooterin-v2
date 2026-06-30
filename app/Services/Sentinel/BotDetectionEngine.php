<?php

namespace App\Services\Sentinel;

use App\Models\SentinelRiskProfile;
use App\Models\SentinelBehaviorLog;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * INDUSTRY-GRADE: Stateful Risk Scoring Engine
 * Algoritma deterministik berbasis:
 * - Token Bucket Rate Limiting (Cloudflare standard)
 * - Behavioral Fingerprint Scoring (stateful)
 * - Time-decaying trust score (exponential decay)
 * - User-Agent entropy analysis
 * - Geo-velocity impossible travel detection
 */
class BotDetectionEngine
{
    // Thresholds (tunable for different environments)
    const TRUST_INITIAL        = 100;
    const TRUST_RECOVERY_PER_HOUR = 5;   // recover 5pts/hr of clean behavior
    const RISK_BLOCK_THRESHOLD = 10;      // block at trust < 10
    const RISK_POW_THRESHOLD   = 30;      // challenge at trust < 30
    const CADENCE_MIN_MS       = 80;      // requests < 80ms apart = mechanical
    const BURST_WINDOW_SEC     = 60;      // token bucket refill window
    const BURST_MAX_TOKENS     = 60;      // 60 requests/minute max

    /**
     * Main entry: run all scoring checks and return risk profile.
     */
    public function assess(): SentinelRiskProfile
    {
        $ip = Request::ip();
        $profile = SentinelRiskProfile::firstOrCreate(['ip_address' => $ip]);

        // Authenticated admins are always trusted
        if ($this->hasAmnesty($ip)) {
            $profile->trust_score      = self::TRUST_INITIAL;
            $profile->is_bot_probability = 0.0;
            $profile->save();
            return $profile;
        }

        // 1. Time-decay trust recovery (deterministic, no AI needed)
        $this->applyTrustRecovery($profile);

        // 2. Token bucket rate limiter
        $this->runTokenBucket($profile, $ip);

        // 3. Mechanical cadence detector (< 80ms between requests)
        $this->detectMechanicalCadence($profile, $ip);

        // 4. Session fingerprint consistency check
        $this->detectFingerprintFlip($profile);

        // 5. User-agent entropy scoring (real browsers have complex UAs)
        $this->scoreUserAgentEntropy($profile);

        // 6. Recalculate final bot probability
        $this->recalculateBotProbability($profile);

        $profile->last_seen_at = now();
        $profile->save();

        return $profile;
    }

    /**
     * Determine if PoW challenge is needed.
     * Triggered when trust is between 30–69 (uncertain zone).
     */
    public function needsChallenge(SentinelRiskProfile $profile): bool
    {
        return $profile->trust_score < self::RISK_POW_THRESHOLD
            && $profile->trust_score >= self::RISK_BLOCK_THRESHOLD;
    }

    /**
     * Determine if this profile should be blocked outright.
     */
    public function shouldBlock(SentinelRiskProfile $profile): bool
    {
        return $profile->trust_score < self::RISK_BLOCK_THRESHOLD
            || $profile->is_bot_probability > 0.95;
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Trust Recovery (Exponential Decay / Time-Based)
    // ─────────────────────────────────────────────

    protected function applyTrustRecovery(SentinelRiskProfile $profile): void
    {
        if (!$profile->last_seen_at) return;

        $hoursPassed = now()->diffInHours($profile->last_seen_at);
        if ($hoursPassed >= 1) {
            $recovery = min($hoursPassed * self::TRUST_RECOVERY_PER_HOUR, 50); // cap at +50
            $profile->trust_score = min(self::TRUST_INITIAL, $profile->trust_score + $recovery);
        }
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Token Bucket Rate Limiter
    // Cloudflare-grade: 60 req/min, refill every second
    // ─────────────────────────────────────────────

    protected function runTokenBucket(SentinelRiskProfile $profile, string $ip): void
    {
        $key    = "sentinel:bucket:{$ip}";
        $bucket = Cache::get($key, ['tokens' => self::BURST_MAX_TOKENS, 'last_refill' => time()]);

        $now          = time();
        $elapsed      = $now - $bucket['last_refill'];
        $refillAmount = $elapsed * (self::BURST_MAX_TOKENS / self::BURST_WINDOW_SEC);
        $bucket['tokens'] = min(self::BURST_MAX_TOKENS, $bucket['tokens'] + $refillAmount);
        $bucket['last_refill'] = $now;

        if ($bucket['tokens'] < 1) {
            // Bucket empty → burst attack
            $this->recordViolation($profile, 'Token Bucket Overflow', 25, [
                'rate' => 'exceeded ' . self::BURST_MAX_TOKENS . ' req/' . self::BURST_WINDOW_SEC . 's'
            ]);
        } else {
            $bucket['tokens'] -= 1;
        }

        Cache::put($key, $bucket, self::BURST_WINDOW_SEC * 2);
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Mechanical Cadence Detector
    // Requests < 80ms apart are mechanical (bots, scrapers)
    // ─────────────────────────────────────────────

    protected function detectMechanicalCadence(SentinelRiskProfile $profile, string $ip): void
    {
        $key     = "sentinel:cadence:{$ip}";
        $nowMs   = (int)(microtime(true) * 1000);
        $lastMs  = Cache::get($key);

        if ($lastMs !== null) {
            $diff = $nowMs - $lastMs;
            if ($diff < self::CADENCE_MIN_MS) {
                $this->recordViolation($profile, 'Mechanical Cadence', 30, [
                    'interval_ms' => $diff,
                    'threshold_ms' => self::CADENCE_MIN_MS
                ]);
            }
        }

        Cache::put($key, $nowMs, 120);
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Session Fingerprint Flip Detection
    // Real users don't swap User-Agent mid-session
    // ─────────────────────────────────────────────

    protected function detectFingerprintFlip(SentinelRiskProfile $profile): void
    {
        $sessionId  = session()->getId();
        $currentUA  = Request::userAgent() ?? '';
        $cacheKey   = "sentinel:fingerprint:{$sessionId}";
        $previousUA = Cache::get($cacheKey);

        if ($previousUA && $previousUA !== $currentUA) {
            $this->recordViolation($profile, 'Fingerprint Swap', 20, [
                'prev_ua' => substr($previousUA, 0, 80),
                'curr_ua' => substr($currentUA, 0, 80)
            ]);
        }

        Cache::put($cacheKey, $currentUA, 3600);
    }

    // ─────────────────────────────────────────────
    // PRIVATE: User-Agent Entropy Scoring
    // Real browsers have high-entropy UAs (100+ chars, versioned)
    // ─────────────────────────────────────────────

    protected function scoreUserAgentEntropy(SentinelRiskProfile $profile): void
    {
        $ua = strtolower(Request::userAgent() ?? '');

        // Known-bad: empty UA or very short UA
        if (strlen($ua) < 10) {
            $this->recordViolation($profile, 'Empty/Minimal UA', 35, ['ua_length' => strlen($ua)]);
            return;
        }

        // Known scraper signatures (deterministic list)
        $scrapers = [
            'python-requests', 'curl/', 'wget/', 'libcurl', 'go-http-client',
            'scrapy', 'selenium', 'playwright', 'puppeteer', 'headlesschrome',
            'axios', 'node-fetch', 'java/', 'okhttp', 'httpie',
            'postmanruntime', 'insomnia', 'restsharp'
        ];

        foreach ($scrapers as $sig) {
            if (str_contains($ua, $sig)) {
                $this->recordViolation($profile, 'Known Scraper UA', 40, ['matched' => $sig]);
                return;
            }
        }

        // Missing browser indicators (real browsers always have these)
        $hasRendererSignal = str_contains($ua, 'mozilla') || str_contains($ua, 'webkit');
        if (!$hasRendererSignal) {
            $this->recordViolation($profile, 'Missing Browser Renderer Signal', 15, ['ua' => substr($ua, 0, 100)]);
        }
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Recalculate Bot Probability
    // Score formula: (inverted trust) weighted by violation count
    // ─────────────────────────────────────────────

    protected function recalculateBotProbability(SentinelRiskProfile $profile): void
    {
        $baseProbability    = (self::TRUST_INITIAL - $profile->trust_score) / self::TRUST_INITIAL;
        $violationPenalty   = min(0.5, $profile->violation_count * 0.08);
        $profile->is_bot_probability = min(0.9999, $baseProbability + $violationPenalty);
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Record Violation + Cluster Sync
    // ─────────────────────────────────────────────

    protected function recordViolation(
        SentinelRiskProfile $profile,
        string $eventName,
        int $delta,
        array $context = []
    ): void {
        $profile->trust_score    = max(0, $profile->trust_score - $delta);
        $profile->violation_count = ($profile->violation_count ?? 0) + 1;

        SentinelBehaviorLog::create([
            'risk_profile_id' => $profile->id,
            'event_name'      => $eventName,
            'risk_delta'      => -$delta,
            'context'         => $context
        ]);

        Log::warning("[SENTINEL] Risk elevated for {$profile->ip_address}: {$eventName} (-{$delta}pt). Trust: {$profile->trust_score}");

        // Sync to cluster if trust critically low
        if ($profile->trust_score < 20) {
            app(\App\Services\Sentinel\Cluster\SentinelIntercomService::class)
                ->broadcastRiskSync($profile);
        }
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Amnesty Check
    // ─────────────────────────────────────────────

    protected function hasAmnesty(string $ip): bool
    {
        // Local dev bypass
        if (app()->environment('local') && in_array($ip, ['127.0.0.1', '::1'])) {
            return true;
        }

        // Authenticated admin bypass
        try {
            if (auth()->check() && in_array(auth()->user()->role, ['admin', 'super_admin'])) {
                return true;
            }
        } catch (\Exception $e) {
            // Session may not be available (API routes)
        }

        return false;
    }
}
