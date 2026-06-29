<?php

namespace App\Services\Seo;

use App\Models\SeoKeyword;
use App\Models\WikiEntity;
use App\Models\SeoAuditLog;


class InterlinkOracleService
{


    /**
     * UNICORP-GRADE: Semantic Internal Linking Engine
     * Transform plain text into a semantically linked SEO network.
     */
    public function oracleLinkContent($content, $currentUrl = null)
    {
        $keywords = SeoKeyword::where('is_active', true)->orderByDesc('priority')->get();
        if ($keywords->isEmpty()) return $content;

        return $this->basicRegexLinker($content, $keywords, $currentUrl);
    }

    protected function basicRegexLinker($content, $keywords, $currentUrl)
    {
        $limit = 3;
        $count = 0;
        
        foreach ($keywords as $k) {
            if ($count >= $limit) break;
            if ($currentUrl && str_contains($k->target_url, $currentUrl)) continue;

            $pattern = '/\b(' . preg_quote($k->keyword, '/') . ')\b(?![^<]*>)/i';
            $replacement = '<a href="' . $k->target_url . '" class="text-primary hover:underline font-semibold">$1</a>';
            
            $newContent = preg_replace($pattern, $replacement, $content, 1, $matches);
            if ($matches > 0) {
                $content = $newContent;
                $count++;
            }
        }
        
        return $content;
    }

    public function processBatchWiki()
    {
        $entities = WikiEntity::where('description', 'not like', '%href%')
            ->limit(10)
            ->get();
            
        foreach ($entities as $entity) {
            /** @var WikiEntity $entity */
            $oldContent = $entity->description;
            $newContent = $this->oracleLinkContent($oldContent, "/wiki/{$entity->slug}");
            
            if ($oldContent !== $newContent) {
                $entity->description = $newContent;
                $entity->save();
                
                SeoAuditLog::create([
                    'event_type' => '[AUTO-OPTIMIZED] INTERLINK-ORACLE',
                    'description' => "Transformed semantic links for Wiki: {$entity->title}",
                    'winner_url' => "/wiki/{$entity->slug}",
                    'previous_state' => ['content' => substr($oldContent, 0, 200)],
                    'new_state' => ['content' => substr($newContent, 0, 200)]
                ]);
            }
        }
    }
}
