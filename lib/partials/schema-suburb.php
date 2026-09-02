<?php
/**
 * Trimmed LocalBusiness JSON-LD for one suburb page. The full business
 * record (complete areaServed list, hasOfferCatalog, openingHours,
 * aggregateRating, geo) stays canonical on index.html/about.html/etc under
 * the same @id — this only re-asserts the entity for a suburb page with
 * that page's specific area, instead of repeating the whole catalog.
 */
function render_suburb_schema(string $suburb): void
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        '@id' => 'https://wastemates.com.au/#business',
        'name' => 'WasteMates',
        'image' => 'https://wastemates.com.au/assets/ph-crew.jpg',
        'url' => 'https://wastemates.com.au/',
        'telephone' => '+61494013254',
        'email' => 'info@wastemates.com.au',
        'priceRange' => '$$',
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => 'Cambridge Road',
            'addressLocality' => 'Mooroolbark',
            'addressRegion' => 'VIC',
            'postalCode' => '3138',
            'addressCountry' => 'AU',
        ],
        'areaServed' => [
            '@type' => 'Place',
            'name' => $suburb,
        ],
    ];
    echo '<script type="application/ld+json">' . "\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n" . '</script>' . "\n";
}
