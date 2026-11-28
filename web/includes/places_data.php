<?php
/**
 * OwnMemories — yer adları için küçük, elle hazırlanmış Türkçe veri (places.php kullanır).
 *
 *  exonyms   : "ÜLKE|GeoNames adı" → Türkçe adı (Rome → Roma). GeoNames 'alternatenames' sütununda dil bilgisi
 *              olmadığından yabancı şehirlerin Türkçe adları buradan gelir. Eşleşme GeoNames 'name' ya da 'asciiname' ile.
 *  regions   : bilinen gezi bölgeleri (merkez + yarıçap km). Bölgedeki fotoğraflar anı başlıklarında bölge adını alır
 *              ("Kapadokya'da bir hafta"); aramada da bulunur.
 *  countries : intl (ICU) yoksa kullanılan Türkçe ülke adları (yaygın olanlar).
 *  locative  : okunuşu yazılışından farklı adların bulunma eki ("Nice" → "te").
 */
declare(strict_types=1);

return [
    'exonyms' => [
        // Avrupa
        'IT|Rome' => 'Roma', 'IT|Milan' => 'Milano', 'IT|Naples' => 'Napoli', 'IT|Venice' => 'Venedik', 'IT|Florence' => 'Floransa',
        'IT|Turin' => 'Torino', 'IT|Genoa' => 'Cenova', 'IT|Syracuse' => 'Siraküza', 'IT|Padova' => 'Padova',
        'VA|Vatican City' => 'Vatikan',
        'GR|Athens' => 'Atina', 'GR|Thessaloniki' => 'Selanik', 'GR|Rhodes' => 'Rodos', 'GR|Mytilene' => 'Midilli', 'GR|Chios' => 'Sakız',
        'GR|Kos' => 'İstanköy', 'GR|Komotini' => 'Gümülcine', 'GR|Xanthi' => 'İskeçe', 'GR|Alexandroupoli' => 'Dedeağaç',
        'GR|Ioannina' => 'Yanya', 'GR|Heraklion' => 'Kandiye', 'GR|Kerkyra' => 'Korfu', 'GR|Corfu' => 'Korfu', 'GR|Larisa' => 'Yenişehir',
        'GB|London' => 'Londra', 'GB|Edinburgh' => 'Edinburg',
        'DE|Munich' => 'Münih', 'DE|Cologne' => 'Köln', 'DE|Nuremberg' => 'Nürnberg',
        'AT|Vienna' => 'Viyana',
        'CH|Geneva' => 'Cenevre', 'CH|Zurich' => 'Zürih', 'CH|Lucerne' => 'Luzern',
        'BE|Brussels' => 'Brüksel', 'BE|Antwerpen' => 'Anvers',
        'NL|The Hague' => 'Lahey',
        'CZ|Prague' => 'Prag',
        'PL|Warsaw' => 'Varşova', 'PL|Krakow' => 'Krakov', 'PL|Kraków' => 'Krakov',
        'HU|Budapest' => 'Budapeşte',
        'RO|Bucharest' => 'Bükreş', 'RO|Constanta' => 'Köstence', 'RO|Constanța' => 'Köstence',
        'BG|Sofia' => 'Sofya', 'BG|Plovdiv' => 'Filibe', 'BG|Kardzhali' => 'Kırcaali', 'BG|Shumen' => 'Şumnu', 'BG|Ruse' => 'Rusçuk',
        'RS|Belgrade' => 'Belgrad',
        'MK|Skopje' => 'Üsküp', 'MK|Ohrid' => 'Ohri', 'MK|Bitola' => 'Manastır',
        'BA|Sarajevo' => 'Saraybosna',
        'AL|Tirana' => 'Tiran',
        'XK|Pristina' => 'Priştine',
        'PT|Lisbon' => 'Lizbon',
        'ES|Seville' => 'Sevilla', 'ES|Sevilla' => 'Sevilla',
        'FR|Strasbourg' => 'Strazburg',
        'DK|Copenhagen' => 'Kopenhag',
        'RU|Moscow' => 'Moskova', 'RU|Saint Petersburg' => 'St. Petersburg',
        'UA|Kyiv' => 'Kiev', 'UA|Odesa' => 'Odessa',
        'GE|Tbilisi' => 'Tiflis', 'GE|Batumi' => 'Batum',
        'AZ|Baku' => 'Bakü', 'AZ|Nakhchivan' => 'Nahçıvan', 'AZ|Ganja' => 'Gence',
        'CY|Nicosia' => 'Lefkoşa', 'CY|Kyrenia' => 'Girne', 'CY|Famagusta' => 'Gazimağusa', 'CY|Limassol' => 'Limasol',
        'CY|Paphos' => 'Baf', 'CY|Larnaca' => 'Larnaka', 'CY|Morphou' => 'Güzelyurt',
        // Orta Doğu ve Afrika
        'EG|Cairo' => 'Kahire', 'EG|Alexandria' => 'İskenderiye',
        'SY|Damascus' => 'Şam', 'SY|Aleppo' => 'Halep',
        'IQ|Baghdad' => 'Bağdat', 'IQ|Mosul' => 'Musul', 'IQ|Kirkuk' => 'Kerkük',
        'IR|Tehran' => 'Tahran', 'IR|Tabriz' => 'Tebriz', 'IR|Isfahan' => 'İsfahan',
        'SA|Mecca' => 'Mekke', 'SA|Medina' => 'Medine', 'SA|Riyadh' => 'Riyad', 'SA|Jeddah' => 'Cidde',
        'IL|Jerusalem' => 'Kudüs', 'PS|East Jerusalem' => 'Doğu Kudüs',
        'LB|Beirut' => 'Beyrut',
        'TN|Tunis' => 'Tunus',
        'DZ|Algiers' => 'Cezayir',
        'MA|Marrakesh' => 'Marakeş', 'MA|Casablanca' => 'Kazablanka', 'MA|Fes' => 'Fas',
        // Asya ve Amerika
        'CN|Beijing' => 'Pekin', 'CN|Shanghai' => 'Şanghay',
        'KR|Seoul' => 'Seul',
        'IN|New Delhi' => 'Yeni Delhi',
        'US|New York City' => 'New York', 'US|Washington, D.C.' => 'Washington',
        'MX|Mexico City' => 'Meksiko',
        'UZ|Samarkand' => 'Semerkant', 'UZ|Bukhara' => 'Buhara', 'UZ|Tashkent' => 'Taşkent',
        'TM|Ashgabat' => 'Aşkabat',
    ],
    'regions' => [
        // ad, enlem, boylam, yarıçap (km), ülke
        ['Kapadokya', 38.643, 34.840, 16.0, 'TR'],
    ],
    'countries' => [
        'TR' => 'Türkiye', 'CY' => 'Kıbrıs', 'GR' => 'Yunanistan', 'BG' => 'Bulgaristan', 'GE' => 'Gürcistan', 'AZ' => 'Azerbaycan',
        'DE' => 'Almanya', 'FR' => 'Fransa', 'IT' => 'İtalya', 'ES' => 'İspanya', 'PT' => 'Portekiz', 'GB' => 'Birleşik Krallık',
        'NL' => 'Hollanda', 'BE' => 'Belçika', 'CH' => 'İsviçre', 'AT' => 'Avusturya', 'CZ' => 'Çekya', 'PL' => 'Polonya',
        'HU' => 'Macaristan', 'RO' => 'Romanya', 'RS' => 'Sırbistan', 'HR' => 'Hırvatistan', 'BA' => 'Bosna-Hersek',
        'MK' => 'Kuzey Makedonya', 'AL' => 'Arnavutluk', 'ME' => 'Karadağ', 'DK' => 'Danimarka', 'SE' => 'İsveç',
        'NO' => 'Norveç', 'FI' => 'Finlandiya', 'IE' => 'İrlanda', 'IS' => 'İzlanda', 'RU' => 'Rusya', 'UA' => 'Ukrayna',
        'US' => 'Amerika Birleşik Devletleri', 'CA' => 'Kanada', 'MX' => 'Meksika', 'BR' => 'Brezilya', 'AR' => 'Arjantin',
        'EG' => 'Mısır', 'MA' => 'Fas', 'TN' => 'Tunus', 'AE' => 'Birleşik Arap Emirlikleri', 'SA' => 'Suudi Arabistan',
        'QA' => 'Katar', 'IL' => 'İsrail', 'JO' => 'Ürdün', 'LB' => 'Lübnan', 'IR' => 'İran', 'IQ' => 'Irak', 'SY' => 'Suriye',
        'TH' => 'Tayland', 'JP' => 'Japonya', 'CN' => 'Çin', 'KR' => 'Güney Kore', 'IN' => 'Hindistan', 'ID' => 'Endonezya',
        'VN' => 'Vietnam', 'MY' => 'Malezya', 'SG' => 'Singapur', 'AU' => 'Avustralya', 'NZ' => 'Yeni Zelanda',
        'VA' => 'Vatikan', 'MT' => 'Malta', 'LU' => 'Lüksemburg', 'MC' => 'Monako', 'SI' => 'Slovenya', 'SK' => 'Slovakya',
        'UZ' => 'Özbekistan', 'KZ' => 'Kazakistan', 'TM' => 'Türkmenistan', 'KG' => 'Kırgızistan', 'XK' => 'Kosova',
    ],
    'locative' => [
        // Okunuşa göre ek: ad → "da" | "de" | "ta" | "te"
        'Nice' => 'te', 'Cannes' => 'da', 'Bordeaux' => 'da', 'Toulouse' => 'da', 'Grenoble' => 'da', 'Versailles' => 'da',
        'Los Angeles' => 'ta', 'Seattle' => 'da', 'Manchester' => 'da', 'Leicester' => 'da', 'Worcester' => 'da',
        'Brighton' => 'da', 'Liverpool' => 'da', 'Glasgow' => 'da', 'Montreal' => 'de', 'Lausanne' => 'da',
    ],
];
