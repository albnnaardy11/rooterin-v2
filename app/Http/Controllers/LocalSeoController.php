<?php

namespace App\Http\Controllers;

use App\Models\SeoCity;
use App\Models\Service;
use App\Models\LocalizedReview;
use Illuminate\Http\Request;
use Artesaos\SEOTools\Facades\SEOTools;

class LocalSeoController extends Controller
{
    /**
     * Tampilkan halaman layanan spesifik di kota tertentu.
     */
    public function show(string $citySlug, string $serviceSlug)
    {
        $city = SeoCity::where('slug', $citySlug)->where('is_active', true)->firstOrFail();
        $service = Service::where('slug', $serviceSlug)->firstOrFail();

        // Trust Architect: Pull localized reviews for this city
        $cityReviews = LocalizedReview::where('seo_city_id', $city->id)->where('is_active', true)->get();
        if ($cityReviews->isEmpty()) {
            $cityReviews = LocalizedReview::whereNull('seo_city_id')->where('is_active', true)->take(3)->get();
        }

        // Semantic Keyword Cloud (LSI)
        $lsiCloud = $city->lsi_keywords ? array_map('trim', explode(',', $city->lsi_keywords)) : [];

        // SEO Magic: Hyper-Localized Content
        $title = "{$service->name} di {$city->name} - Pipa Mampet Beres!";
        $description = "Cari {$service->name} di {$city->name}? Rooterin hadir dengan layanan profesional di wilayah {$city->name} dan sekitarnya. Terbukti amanah & bergaransi.";

        SEOTools::setTitle($title);
        SEOTools::setDescription($description);

        // Dynamic Urgency Slogan
        $urgencySlogans = [
            "Tukang Pipa Terdekat di {$city->name} - Tiba dalam 15 Menit!",
            "Diskon Khusus Area {$city->name} Hari Ini - Bergaransi!",
            "Ahli Saluran Mampet {$city->name} - Bayar Setelah Lancar!"
        ];
        $urgency = $urgencySlogans[array_rand($urgencySlogans)];

        // Programmatic Schema.org (JSON-LD)
        $schema = [
            "@context" => "https://schema.org",
            "@type" => "Service",
            "name" => $service->name . " di " . $city->name,
            "provider" => [
                "@type" => "LocalBusiness",
                "name" => "RooterIN " . $city->name,
                "telephone" => "+6281234567890", // Ganti dengan nomor aktual
                "priceRange" => "$$"
            ],
            "areaServed" => [
                "@type" => "City",
                "name" => $city->name
            ],
            "description" => $description
        ];
        $schemaJson = json_encode($schema);

        return view('local-seo.service-city', compact('city', 'service', 'cityReviews', 'lsiCloud', 'urgency', 'schemaJson'));
    }

    /**
     * Tampilkan landing page utama untuk kota tertentu.
     */
    public function cityLanding(string $citySlug)
    {
        $city = SeoCity::where('slug', $citySlug)->where('is_active', true)->firstOrFail();
        $services = Service::where('is_active', true)->get();
        
        $cityReviews = LocalizedReview::where('seo_city_id', $city->id)->where('is_active', true)->get();
        if ($cityReviews->isEmpty()) {
            $cityReviews = LocalizedReview::whereNull('seo_city_id')->where('is_active', true)->take(3)->get();
        }

        $lsiCloud = $city->lsi_keywords ? array_map('trim', explode(',', $city->lsi_keywords)) : [];

        $title = "Jasa Pipa Mampet & Rooterin Terbaik di {$city->name}";
        $description = "Solusi mampet nomor 1 di {$city->name}. Kami melayani seluruh area {$city->name} dengan peralatan modern.";

        SEOTools::setTitle($title);
        SEOTools::setDescription($description);

        // Dynamic Urgency Slogan
        $urgencySlogans = [
            "Jasa Saluran Mampet Tercepat di {$city->name} - Respon 10 Menit!",
            "Solusi Pipa Penuh Area {$city->name} - Garansi Tanpa Bongkar!",
            "Tukang Rooter Profesional {$city->name} - Harga Jujur!"
        ];
        $urgency = $urgencySlogans[array_rand($urgencySlogans)];

        // Programmatic Schema.org (JSON-LD)
        $schema = [
            "@context" => "https://schema.org",
            "@type" => "LocalBusiness",
            "name" => "RooterIN " . $city->name,
            "telephone" => "+6281234567890", 
            "priceRange" => "$$",
            "areaServed" => [
                "@type" => "City",
                "name" => $city->name
            ],
            "description" => $description
        ];
        $schemaJson = json_encode($schema);

        return view('local-seo.city-index', compact('city', 'services', 'cityReviews', 'lsiCloud', 'urgency', 'schemaJson'));
    }
}
