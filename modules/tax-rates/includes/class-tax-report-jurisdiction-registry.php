<?php
/**
 * Official filing-jurisdiction registries used by Sales Tax Reports.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Report_Jurisdiction_Registry
{
    const GEORGIA_SOURCE = 'Georgia Sales and Use Tax Rate Chart - Effective October 1, 2026';
    const GEORGIA_EFFECTIVE_FROM = '2026-10-01';

    /** @var array<string,array<string,string>>|null */
    private static $georgia_entries = null;

    /**
     * Resolve an order to the official Georgia filing code.
     *
     * Other states return null so their existing resolver remains untouched.
     * Georgia returns an explicit Needs Review row when no official code can
     * be proven; WooCommerce tax-line labels are never promoted to invented
     * filing jurisdictions.
     */
    public static function resolve(array $location, array $components = []): ?array
    {
        if (strtoupper((string) ($location['country'] ?? '')) !== 'US'
            || strtoupper((string) ($location['state'] ?? '')) !== 'GA') {
            return null;
        }

        $entries = self::georgia_entries();
        $texts = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            foreach (['code', 'jurisdictionCode', 'jurisdiction_code'] as $field) {
                $code = self::normalize_code((string) ($component[$field] ?? ''));
                if ($code !== '' && $code !== '000' && isset($entries[$code])) {
                    return self::result($entries[$code]);
                }
            }

            foreach (['name', 'jurisdiction', 'label', 'rate_code'] as $field) {
                $value = trim((string) ($component[$field] ?? ''));
                if ($value !== '') {
                    $texts[] = $value;
                }
            }
        }

        $search = self::normalize_text(implode(' | ', $texts));
        $city = self::normalize_text((string) ($location['city'] ?? ''));
        $county_code = self::match_georgia_county_code($search, $entries);
        if ($county_code === '') {
            foreach ($texts as $text) {
                $county_code = self::match_georgia_county_code(self::normalize_text($text), $entries);
                if ($county_code !== '') {
                    break;
                }
            }
        }
        $filing_code = self::special_georgia_code($county_code, $city, $search);

        if ($filing_code !== '' && isset($entries[$filing_code])) {
            return self::result($entries[$filing_code]);
        }

        return [
            'type' => 'unmapped',
            'name' => 'Unmapped Georgia jurisdiction',
            'code' => '',
            'status' => 'needs_review',
            'registry_source' => self::GEORGIA_SOURCE,
            'registry_effective_from' => self::GEORGIA_EFFECTIVE_FROM,
        ];
    }

    public static function state_filing_code(string $country, string $state): string
    {
        return strtoupper($country) === 'US' && strtoupper($state) === 'GA' ? '000' : '';
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function georgia_entries(): array
    {
        if (self::$georgia_entries !== null) {
            return self::$georgia_entries;
        }

        $data = <<<'DATA'
000|State|state
001|Appling|county
002|Atkinson|county
003|Bacon|county
004|Baker|county
005|Baldwin|county
006|Banks|county
007|Barrow|county
008|Bartow|county
009|Ben Hill|county
010|Berrien|county
011|Bibb|county
012|Bleckley|county
013|Brantley|county
014|Brooks|county
015|Bryan|county
016|Bulloch|county
017|Burke|county
018|Butts|county
019|Calhoun|county
020|Camden|county
021|Candler|county
022|Carroll|county
023|Catoosa|county
024|Charlton|county
025|Chatham|county
026|Chattahoochee|county
027|Chattooga|county
028|Cherokee|county
029|Clarke|county
030|Clay|county
031|Clayton (Not College Park)|county
032|Clinch|county
033|Cobb|county
034|Coffee|county
035|Colquitt|county
036|Columbia|county
037|Cook|county
038|Coweta|county
039|Crawford|county
040|Crisp|county
041|Dade|county
042|Dawson|county
043|Decatur|county
044|DeKalb (Not Atlanta)|county
044A|DeKalb (Atlanta)|special
045|Dodge|county
046|Dooly|county
047|Dougherty|county
048|Douglas|county
049|Early|county
050|Echols|county
051|Effingham|county
052|Elbert|county
053|Emanuel|county
054|Evans|county
055|Fannin|county
056|Fayette|county
057|Floyd|county
058|Forsyth|county
059|Franklin|county
060|Fulton|county
060A|Fulton (Atlanta)|special
061|Gilmer|county
062|Glascock|county
063|Glynn|county
064|Gordon|county
065|Grady|county
066|Greene|county
067|Gwinnett|county
068|Habersham|county
069|Hall|county
070|Hancock|county
071|Haralson|county
072|Harris|county
073|Hart|county
074|Heard|county
075|Henry|county
076|Houston|county
077|Irwin|county
078|Jackson|county
079|Jasper|county
080|Jeff Davis|county
081|Jefferson|county
082|Jenkins|county
083|Johnson|county
084|Jones|county
085|Lamar|county
086|Lanier|county
087|Laurens|county
088|Lee|county
089|Liberty|county
090|Lincoln|county
091|Long|county
092|Lowndes|county
093|Lumpkin|county
094|Macon|county
095|Madison|county
096|Marion|county
097|McDuffie|county
098|McIntosh|county
099|Meriwether|county
100|Miller|county
101|Mitchell|county
102|Monroe|county
103|Montgomery|county
104|Morgan|county
105|Murray|county
106|Muscogee|county
107|Newton|county
108|Oconee|county
109|Oglethorpe|county
110|Paulding|county
111|Peach|county
112|Pickens|county
113|Pierce|county
114|Pike|county
115|Polk|county
116|Pulaski|county
117|Putnam|county
118|Quitman|county
119|Rabun|county
120|Randolph|county
121|Richmond|county
122|Rockdale|county
123|Schley|county
124|Screven|county
125|Seminole|county
126|Spalding|county
127|Stephens|county
128|Stewart|county
129|Sumter|county
130|Talbot|county
131|Taliaferro|county
132|Tattnall|county
133|Taylor|county
134|Telfair|county
135|Terrell|county
136|Thomas|county
137|Tift|county
138|Toombs|county
139|Towns|county
140|Treutlen|county
141|Troup|county
142|Turner|county
143|Twiggs|county
144|Union|county
145|Upson|county
146|Walker|county
147|Walton|county
148|Ware|county
149|Warren|county
150|Washington|county
151|Wayne|county
152|Webster|county
153|Wheeler|county
154|White|county
155|Whitfield|county
156|Wilcox|county
157|Wilkes|county
158|Wilkinson|county
159|Worth|county
800|Fulton (Hapeville)|special
801|Fulton (College Park)|special
802|Fulton (East Point)|special
803|Fulton (Central Yards)|special
804|Clayton (College Park)|special
805|Fulton (South Downtown)|special
DATA;

        $entries = [];
        foreach (preg_split('/\R/', trim($data)) as $line) {
            $parts = explode('|', $line);
            if (count($parts) !== 3) {
                continue;
            }
            $entries[$parts[0]] = [
                'code' => $parts[0],
                'name' => $parts[1],
                'type' => $parts[2],
            ];
        }

        self::$georgia_entries = $entries;
        return self::$georgia_entries;
    }

    private static function match_georgia_county_code(string $search, array $entries): string
    {
        if ($search === '') {
            return '';
        }

        foreach ($entries as $code => $entry) {
            if ($entry['type'] !== 'county') {
                continue;
            }
            $county = preg_replace('/\s+\(.+$/', '', self::normalize_text($entry['name']));
            if ($county === '') {
                continue;
            }
            $quoted = preg_quote($county, '/');
            if (preg_match('/(?:^|\s)' . $quoted . '\s+COUNTY(?:\s|$)/', $search)
                || preg_match('/(?:^|\s)COUNTY\s+OF\s+' . $quoted . '(?:\s|$)/', $search)
                || preg_match('/^' . $quoted . '$/', $search)) {
                return $code;
            }
        }

        return '';
    }

    private static function special_georgia_code(string $county_code, string $city, string $search): string
    {
        if ($county_code === '060') {
            if (strpos($search, 'CENTRAL YARDS') !== false || strpos($search, 'CENT YARDS') !== false) {
                return '803';
            }
            if (strpos($search, 'SOUTH DOWNTOWN') !== false || strpos($search, 'S DOWNTN') !== false) {
                return '805';
            }
            if ($city === 'HAPEVILLE') {
                return '800';
            }
            if ($city === 'COLLEGE PARK') {
                return '801';
            }
            if ($city === 'EAST POINT') {
                return '802';
            }
            if ($city === 'ATLANTA') {
                return '060A';
            }
        }

        if ($county_code === '044' && $city === 'ATLANTA') {
            return '044A';
        }
        if ($county_code === '031' && $city === 'COLLEGE PARK') {
            return '804';
        }

        return $county_code;
    }

    private static function result(array $entry): array
    {
        return [
            'type' => $entry['type'],
            'name' => $entry['name'],
            'code' => $entry['code'],
            'status' => 'ready',
            'registry_source' => self::GEORGIA_SOURCE,
            'registry_effective_from' => self::GEORGIA_EFFECTIVE_FROM,
        ];
    }

    private static function normalize_code(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^(?:\d{3}|\d{3}A)$/', $code) ? $code : '';
    }

    private static function normalize_text(string $text): string
    {
        $text = strtoupper(trim($text));
        $text = preg_replace('/[^A-Z0-9]+/', ' ', $text);
        return trim((string) preg_replace('/\s+/', ' ', (string) $text));
    }
}
