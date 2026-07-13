# wcioWGCSSP

Opsætningsguide: Woo Gift Cards synchronize Customers 1st.
Denne guide beskriver, hvordan du installerer og konfigurerer pluginet "Woo Gift Cards synchronize Customers 1st." (`wcio-woo-servicepos-gift-card`), som synkroniserer gavekort mellem YITH WooCommerce Gift Cards og Customers 1st (C1ST).
---
1. Forudsætninger
Før du installerer pluginet, skal følgende være på plads:
WordPress med WooCommerce installeret og aktiveret
YITH WooCommerce Gift Cards-pluginet installeret og aktiveret
En aktiv aftale med Customers 1st (C1ST), herunder:
Et API-token til C1ST
Et Resthook secret (bruges til at verificere webhooks fra C1ST)
Adgang til at oprette/konfigurere resthooks (webhooks) i C1ST-systemet
PHP 7.0 eller nyere
---
2. Installation
Download eller klon pluginmappen `wcio-woo-servicepos-gift-card`.
Upload mappen til `/wp-content/plugins/` på din WordPress-installation (via FTP/SFTP eller fx et hosting-panel).
Gå til WordPress Admin → Plugins.
Find "Woo Gift Cards synchronize Customers 1st." i listen, og klik Aktivér.
> Pluginet indeholder en indbygget opdateringstjekker (Plugin Update Checker), som holder øje med GitHub-repoet `websitecareio/wcio-woo-servicepos-gift-card` (branch `main`). Du får derfor besked i WordPress, når der er en ny version.
---
3. Konfiguration af indstillinger
Pluginet tilføjer en ny fane under WooCommerce-indstillinger:
Gå til WooCommerce → Indstillinger.
Klik på fanen "C1ST Giftcard".
Udfyld følgende felter:
Felt	Beskrivelse
C1ST Token	API-token fra Customers 1st, bruges til autorisation (`Authorization: Bearer <token>`) ved alle kald til C1ST's API.
Resthook secret	Den hemmelige nøgle, der bruges til at validere HMAC-signaturen på indkommende webhooks fra C1ST. Skal matche præcis det, der er sat op i C1ST.
Gift card plugin	Vælg hvilket gavekort-plugin der bruges på siden. Pt. understøttes kun YITH WooCommerce Gift Cards.
Klik Gem ændringer.

<img width="1591" height="616" alt="image" src="https://github.com/user-attachments/assets/31327260-7ca8-4c82-865d-8bf254fa561a" />

---
4. Opsætning af webhooks i Customers 1st
Pluginet eksponerer to REST-endpoints, som C1ST skal kalde, når der sker ændringer i gavekort:
Event	Endpoint	Metode
Gavekort oprettet	`https://dindomæne.dk/wp-json/c1st/v1/giftcard.created`	POST
Gavekort opdateret	`https://dindomæne.dk/wp-json/c1st/v1/giftcard.updated`	POST
Sådan konfigureres det i C1ST:
Log ind i Customers 1st (eller det tilknyttede Deltateq-baserede system).
Opret to resthooks/webhooks, der peger på ovenstående URL'er.
Sæt begge webhooks til at sende:
En header `X-C1st-Webhook-Signature` med en HMAC-SHA256-signatur af payload'en, beregnet med det samme Resthook secret, som er indtastet i WordPress.
Payload som JSON, i det format C1ST normalt sender (indeholder bl.a. `giftcardno`, `amount`, `amountspent`, `store`, m.m.).
> Uden korrekt signatur vil pluginet afvise kaldet med **HTTP 401**.
---
5. Sådan fungerer synkroniseringen
Fra WooCommerce → C1ST
Nyt gavekort oprettet (via ordre eller manuelt i WP-admin): sendes automatisk til C1ST som et nyt gavekort.
Gavekort opdateret (fx saldoændring): pluginet søger efter kortet i C1ST via gavekortnummer og opdaterer saldoen (`amountspent`).
Kladde-ordrer (checkout-draft): Hvis en ordre med et gavekort-kupon bliver til en kladde, lægges beløbet automatisk tilbage på gavekortets saldo, indtil ordren rent faktisk gennemføres.
Fra C1ST → WooCommerce (via webhooks)
`giftcard.created`: Opretter et nyt gavekort i WooCommerce, hvis gavekortnummeret ikke allerede findes.
`giftcard.updated`: Finder det eksisterende gavekort via gavekortnummer og opdaterer saldoen ud fra `amount - amountspent`.
---
6. Test af opsætningen
Test fra WooCommerce-siden: Opret et testgavekort via YITH-pluginet, og bekræft, at det dukker op i C1ST kort efter (der er indbygget en 1 sekunds delay pr. API-kald, så det sker ikke øjeblikkeligt).
Test fra C1ST-siden: Opret eller opdatér et gavekort i C1ST, og bekræft at det oprettes/opdateres korrekt i WooCommerce.
Tjek fejlmeddelelser: Hvis noget fejler, tjek:
At token og resthook secret er korrekt indtastet
At URL'erne i C1ST peger på de rigtige endpoints
PHP error log for eventuelle fejl fra pluginet
---
7. Fejlfinding
Problem	Mulig årsag
Webhook fejler med 401	Resthook secret matcher ikke mellem WordPress og C1ST, eller signaturen beregnes forkert i C1ST.
Gavekort oprettes ikke i C1ST	Tjek at C1ST Token er korrekt og gyldigt. Hvis C1ST's API svarer med statuskode ≥300, stopper scriptet stille uden brugerbesked — tjek PHP-loggen.
"Gift Card not found in WooCommerce!" (412)	Gavekortnummeret i webhook-payloaden matcher ikke titlen på nogen `gift_card`-post i WordPress.
Dobbeltoprettelse undgås	Pluginet tjekker automatisk, om et gavekort med samme nummer allerede findes, før det opretter et nyt (returnerer 409, hvis det allerede findes).
---
8. Vigtige tekniske noter
Alle kald til C1ST går til base-URL'en `https://app.deltateq.com/api`.
Der er en indbygget 1 sekunds pause efter hvert API-kald til C1ST for at undgå at overbelaste API'et.
Mappen `includes/yith-woocommerce-gift-cards.php` indeholder ekstra cron-baseret synkroniseringslogik, men er ikke aktiveret i den nuværende version af pluginet (er udkommenteret i hovedfilen).
---
Denne guide er baseret på gennemgang af koden i `wcio-woo-servicepos-gift-card` v1.4.4.
