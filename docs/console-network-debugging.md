# AI Translate: Dev Console & Network Debugging

Dit document beschrijft wat te controleren in de browser DevTools (Console + Network) en welke fouten/waarschuwingen je kunt tegenkomen.

## Waar moet ik kijken? (Snelle gids)

1. **F12** drukken (of rechtermuisknop → Inspecteren)
2. **Console**-tab (bovenin): rode fouten = probleem, geel = waarschuwing
3. **Network**-tab: filter op `batch-strings` → klik op de request → Status moet **200** zijn
4. **Geen rode fouten + batch-strings 200** = alles OK

## 1. Console-controlle

### Te controleren

- **Rode fouten**: JavaScript exceptions
- **Gele waarschuwingen**: o.a. CORS, mixed content, deprecations
- **AI Translate-berichten**: bij fouten logt de plugin nu expliciet naar de console (zie onder)

### Mogelijke JavaScript-fouten

| Oorzaak | Fout | Oplossing |
|---------|------|-----------|
| Geen `.ai-trans-btn` in DOM | `Cannot read property 'addEventListener' of null` | Wordt nu afgevangen met `if(!b)return` |
| `el.closest` of `n.matches` niet ondersteund | `closest is not a function` | Alleen op zeer oude browsers |
| `AI_TA` undefined (script corrupt) | `AI_TA is not defined` | Controleer of script-tag correct wordt hersteld (placeholder-restore) |

## 2. Batch-strings request (`/wp-json/ai-translate/v1/batch-strings`)

### Verwacht gedrag

- **Moment**: ~1,5 s na `window.load` (aanpasbaar in code)
- **Method**: POST
- **Content-Type**: `application/json`
- **Body**: `{nonce, lang, strings}`
- **Response 200**: `{success: true, data: {map: {...}}}`

### Console-berichten bij fout

De plugin logt nu expliciet bij problemen:

- **`AI Translate: batch-strings failed 403 Forbidden`** → autorisatieprobleem (zie CORS/referer)
- **`AI Translate: batch-strings failed 429 Too Many Requests`** → rate limit (30 req/min per IP)
- **`AI Translate: batch-strings JSON parse error`** → ongeldige response (bijv. PHP-fout of HTML)
- **`AI Translate: batch-strings network error`** → netwerkfout of CORS

## 3. CORS en referer

### Same-origin

De API draait op hetzelfde domein als de pagina. CORS is dan normaal gesproken geen issue.

### Wanneer wél problemen

- **http vs https**: `http://viool-docente.nl` vs `https://viool-docente.nl` → verschillende origin
- **www vs niet-www**: `www.viool-docente.nl` vs `viool-docente.nl` → verschillende origin
- **Referer geblokkeerd**: sommige browsers/extensions blokkeren Referer

### Permission callback

De endpoint controleert:

1. **Bots**: User-Agent check → geen nonce nodig
2. **Referer**: moet beginnen met `home_url()` (zelfde domein)
3. **Nonce**: `ai_translate_front_nonce` of `wp_rest`
4. **Ingelogde gebruikers**: geldige nonce verplicht
5. **Niet-ingelogde gebruikers**: als referer/nonce faalt, mag een geldige Origin die begint met `home_url()` voldoende zijn

### Mogelijke 403-oorzaken

- `home_url()` (bijv. https) ≠ paginadomein (bijv. http)
- Referer ontbreekt (blokkerende extensie)
- Verouderde nonce (cache van oude pagina)

## 4. Network-tab checklist

1. **Filter**: `batch-strings`
2. **Status**: 200 = ok
3. **Tijd**: typisch &lt; 2 s bij cache hit; langer bij API-aanroep
4. **Request payload**: `{nonce, lang, strings: [...]}`
5. **Response**: JSON met `success` en `data.map`

## 5. Aanpassingen in deze release

- Delay vóór `tA()`: 3000 ms → 1500 ms
- Null-check voor `.ai-trans-btn` toegevoegd (voorkomt crash)
- Console.error bij batch-strings-fouten (403, 429, netwerk, JSON parse)
