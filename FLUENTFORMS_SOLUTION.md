# FluentForms Dropdown Validatie Oplossing

## Probleem
FluentForms dropdowns werken niet goed qua validatie omdat de opties vertaald worden, maar de value-attributen van de `<option>`-tags blijven in de originele taal. Hierdoor komt de value die naar de server gestuurd wordt niet overeen met de verwachte waarde, waardoor validatie faalt.

## Oplossing in Twee Stappen

### Stap 1: Code kopiëren van NetCare class
De bestaande FluentForms logica uit `class-ai-translate-core-netcare.php` moet worden gebruikt in plaats van eigen oplossingen.

**Wat er moet gebeuren:**
- Gebruik de exacte FluentForms implementatie uit het voorbeeldbestand
- Niet zelf regex of vertaalgedrag aanpassen
- Geen eigen oplossingen, alleen de bestaande aanpak volgen

**Code uit voorbeeldbestand:**
```php
// In class-ai-translate-core-netcare.php
public function conditionally_add_fluentform_filter(): void
{
    add_filter('the_content', [$this, 'translate_fluentform_fields'], 15);
}

public function translate_fluentform_fields(string $content): string
{
    // Check if this is a FluentForm by looking for common FluentForm elements
    if (
        strpos($content, 'fluentform') === false &&
        strpos($content, 'ff-el-form') === false &&
        strpos($content, 'ff-el-input') === false &&
        strpos($content, 'ff-btn') === false &&
        strpos($content, 'ff-field_container') === false &&
        strpos($content, 'ff-el-group') === false &&
        strpos($content, 'ff-t-container') === false &&
        strpos($content, 'ff-t-cell') === false &&
        strpos($content, 'ff-el-input--label') === false &&
        strpos($content, 'ff-el-input--content') === false &&
        strpos($content, 'ff-el-form-control') === false &&
        strpos($content, 'ff_form_instance') === false
    ) {
        return $content;
    }

    // Extract placeholders, labels, buttons, submit buttons
    // GEEN option teksten vertalen in deze stap
    // Dit voorkomt validatieproblemen
}
```

### Stap 2: Option values vertalen
Nadat de basis FluentForms logica werkt, kunnen de option values worden vertaald zonder validatie te breken.

**Wat er moet gebeuren:**
- Option teksten extraheren uit `<option value="...">tekst</option>`
- Alleen de zichtbare tekst vertalen, niet de value-attribuut
- De value blijft origineel voor validatie
- De zichtbare tekst wordt vervangen met de vertaling

**Code voor option vertaling:**
```php
// Extract option texts (alleen de zichtbare tekst, niet de value)
preg_match_all('/<option[^>]*value=[\'\"][^\'\"]*[\'\"][^>]*>([^<]+)<\/option>/i', $content, $option_matches, PREG_OFFSET_CAPTURE);
foreach ($option_matches[1] as $index => $match) {
    $option_text = $match[0];
    $position = $option_matches[0][$index][1];
    $key = 'option_' . $index;
    $fields_to_translate[$key] = $option_text;
    $field_mappings[$key] = [
        'type' => 'option',
        'original' => $option_text,
        'position' => $position,
        'full_match' => $option_matches[0][$index][0]
    ];
}

// In de switch statement:
case 'option':
    // Vervang alleen de zichtbare tekst tussen <option>...</option>, niet de value
    $content = str_replace(
        '>' . $mapping['original'] . '<',
        '>' . esc_html($translated_text) . '<',
        $content
    );
    break;
```

## Resultaat
- ✅ FluentForms dropdowns werken correct qua validatie
- ✅ Option teksten worden vertaald voor de gebruiker
- ✅ Value-attributen blijven origineel voor validatie
- ✅ Geen validatieproblemen meer

## Voorbeeld Resultaat
```html
<!-- Origineel -->
<option value="Detachering">Detachering</option>

<!-- Na vertaling -->
<option value="Detachering">Secondment</option>
``` 