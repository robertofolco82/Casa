# RAVIOLA32 — pubblicazione sul web aperto

Documento di lavoro del 13 settembre 2026. Prezzi verificati via ricerca web
in quella data: vanno ricontrollati prima di firmare qualsiasi contratto.

## 1. Cosa cambia rispetto a oggi

Fino a ieri il progetto era un hub privato per una casa sola. Da oggi è un
prodotto per utenti che non conosciamo. Il cambiamento vero non è estetico:

| | Oggi (artifact) | Domani (web aperto) |
|---|---|---|
| Utenti | uno, l'autore | molti, sconosciuti |
| Dati | un solo archivio condiviso | un archivio per utente, isolato |
| Accesso | chi ha il link dentro l'organizzazione | chiunque, con account |
| Pagamenti | nessuno | Premium ricorrente |
| AI | il modello della pagina | chiavi nostre o dell'utente |

## 2. Il muro dell'artifact: cosa non può starci, e perché

Non è una scelta di comodo, sono tre vincoli tecnici rigidi.

1. **Nessun server.** L'artifact è una pagina. Non esiste un posto dove far
   girare codice nostro che l'utente non possa leggere o modificare. Login,
   pagamenti e chiavi API richiedono tutti un lato server fidato.
2. **La CSP blocca la rete.** La pagina non può chiamare Stripe, Supabase,
   un servizio di rendering o qualunque altro host. L'unica uscita è il
   ponte verso i connettori di chi guarda (usato oggi per Google Drive):
   funziona per l'autore, non per un utente pubblico.
3. **Il database dell'artifact è interno all'organizzazione.** Non diventa
   multi-utente nemmeno condividendo il link.

Conseguenza pratica: **l'artifact resta il prototipo**, ottimo per mostrare
il prodotto e per l'uso personale su Raviola 32. Il servizio pubblico è un
deploy separato che riusa lo stesso codice di interfaccia.

## 3. Lo stack proposto

```
   Browser / App mobile
          │
          ├── Frontend statico (la SPA attuale)          Hostinger
          │
          ├── Supabase                        auth · dati · file · funzioni
          │     ├── Auth            email+password, reset, cancellazione
          │     ├── Postgres + RLS  un archivio per utente, isolato a livello DB
          │     ├── Storage         planimetrie, preventivi, fatture
          │     └── Edge Functions  il lato fidato: chiavi, webhook, proxy AI
          │
          ├── Stripe                abbonamenti Premium, carte + wallet
          ├── Fatture in Cloud      fattura elettronica verso SDI
          ├── Claude API            benchmark e chat (chiavi nostre)
          └── fal.ai / Replicate    rendering degli ambienti
```

Il punto architetturale che tiene in piedi tutto: **nessuna chiave segreta
tocca mai il browser.** Frontend → Edge Function → servizio esterno. Sempre.

## 4. Servizi di terze parti, uno per uno

### 4.1 Hostinger — frontend
Già disponibile. La SPA è un file HTML senza build: si serve come sito
statico. I piani Business e Cloud permettono anche deploy Node da GitHub;
per il nostro caso basta lo statico. Serve un dominio e il certificato TLS
(incluso). **Costo: quello dell'hosting già pagato + dominio.**

Alternativa se in futuro servisse SSR: Vercel o Netlify, entrambi con free
tier generoso. Non serve adesso.

### 4.2 Supabase — account, dati, file
Progetto già creato (ref `rusiwikqzxzyjrnihrgm`, regione eu-west-1, Postgres 17.6),
schema applicato ma non ancora usato dall'app.

- **Auth**: email + password, verifica email, reset password, cancellazione
  account. Nessun servizio terzo aggiuntivo.
- **RLS**: l'isolamento fra utenti si fa a livello di database, non di
  codice applicativo. È la differenza fra "un bug espone i dati altrui" e
  "un bug non basta a esporli".
- **Storage**: i file degli utenti. Sostituisce Google Drive, che resta
  legato all'account personale dell'autore e non è replicabile per il pubblico.
- **Edge Functions**: il lato fidato. Qui vivono le chiavi e i webhook.

**Costo: Free $0** (500 MB di database, 50.000 utenti attivi al mese) →
**Pro $25/mese** quando si superano i limiti. Team $599/mese solo se serve
la certificazione SOC2.

### 4.3 Stripe — pagamenti
- **Commissioni Italia**: 1,5% + 0,25 € per carte standard SEE; 1,9% + 0,25 €
  per carte premium/business SEE; 2,9% + 0,25 € per carte extra-europee.
  Nessun canone fisso né costo di attivazione.
- **Metodi**: carte, wallet (Apple/Google Pay), SEPA. Per **PayPal** va
  verificata l'attivazione sull'account italiano al momento del setup:
  non l'ho confermata in questa ricerca, non darla per scontata.
- **Stripe Billing** per gli abbonamenti ricorrenti; **Stripe Tax** per
  calcolare l'IVA corretta quando si vende fuori Italia.
- **Stripe Checkout** ospitato: riduce drasticamente il perimetro di
  conformità PCI, perché i dati della carta non passano mai da noi.

### 4.4 Fattura elettronica — Fatture in Cloud (o equivalente)
Stripe **non** emette fatture verso SDI. Due obblighi distinti:

1. **Fatture ai clienti**: vanno generate e trasmesse a SDI. Fatture in
   Cloud è il gestionale più diffuso in Italia e si integra con Stripe.
2. **Autofattura sulle commissioni Stripe**: Stripe Payments Europe è
   irlandese, quindi le sue commissioni sono acquisto di servizi
   dall'estero → reverse charge con autofattura **TD17**, da trasmettere a
   SDI entro il 15 del mese successivo. È un adempimento del venditore,
   non di Stripe.

**Da chiarire con il commercialista prima del lancio**, non dopo: forma
giuridica, regime IVA, e se si vende a utenti UE fuori Italia (regime OSS).

### 4.5 Claude API — benchmark e chat
Prezzi per milione di token (input / output):

| Modello | Input | Output | Quando |
|---|---|---|---|
| Claude Opus 5 | $5 | $25 | benchmark complessi, analisi documenti |
| Claude Sonnet 5 | $2 | $10 | chat corrente, buon compromesso |
| Claude Haiku 4.5 | $1 | $5 | classificazioni, estrazioni brevi |

Due leve per tenere basso il costo del piano gratuito: **prompt caching**
(il contesto della casa si ripete a ogni messaggio, e rileggerlo dalla
cache costa una frazione) e **modello per compito** — non serve il modello
più potente per capire in che cartella va un PDF.

### 4.6 Rendering degli ambienti
Serve un'API, non un servizio con interfaccia web. Le due piattaforme di
riferimento:

- **fal.ai**: da ~$0,001 a $0,15 per immagine secondo il modello. FLUX
  schnell $0,025, FLUX pro $0,05, FLUX 1.1 pro Ultra $0,06. Costruita per
  bassa latenza: adatta a un utente che aspetta davanti allo schermo.
- **Replicate**: catalogo più ampio, documentazione migliore, ma cold start
  di 20-60 secondi sui modelli inattivi. Da valutare per lavori in coda,
  non interattivi.

A ~$0,05 per immagine, un pacchetto Premium con 40 rendering al mese costa
2 dollari di inferenza: sostenibile. Verificare la **licenza commerciale**
del modello scelto prima di venderne l'output.

### 4.7 App mobile — Capacitor
La SPA è già responsive e testata a 390px. **Capacitor** la impacchetta in
un'app nativa iOS e Android mantenendo **una sola base di codice**: la
stessa pagina, dentro un guscio nativo, con accesso a fotocamera e file.

Costi ricorrenti non evitabili: **Apple Developer $99/anno**, **Google Play
$25 una tantum**. Da mettere in conto anche il tempo delle revisioni degli
store, che sui primi invii è imprevedibile.

## 5. "Collega la tua AI" — la parte delicata

Custodire la chiave API di un altro è una responsabilità seria: se trapela,
paga lui. Regole non negoziabili:

1. La chiave **non passa e non resta mai nel browser**.
2. Cifrata a riposo con una chiave che vive solo nelle Edge Function, mai
   nel database accanto al dato cifrato.
3. Mostrata sempre e solo mascherata dopo il salvataggio.
4. Revocabile e cancellabile in un clic, con effetto immediato.
5. Dove il fornitore offre OAuth, si usa OAuth e non si tocca la chiave.

Va detto con chiarezza nell'interfaccia: **con la propria chiave, i consumi
li paga l'utente.** È il senso dell'opzione, ma deve saperlo prima.

## 6. GDPR

- **Regione europea**: il progetto Supabase è già su eu-west-1, i dati
  restano in UE.
- **Nota onesta**: Supabase è società statunitense e ospita su AWS. Anche
  con regione europea resta esposta al CLOUD Act. Va scritto nella privacy
  policy, non nascosto.
- **DPA da firmare**: Supabase, Stripe, Anthropic. Ognuno diventa
  responsabile del trattamento per la sua parte.
- **Informativa**: dire esplicitamente che i contenuti dell'utente vengono
  inviati a un fornitore di AI per generare i confronti e le risposte.
- **Diritti**: export dei dati e cancellazione completa dell'account, con
  effetto reale a cascata su database e storage. Non è una promessa da
  scrivere nella policy, è codice da implementare e testare.
- **Minori**: il servizio non è destinato a minori di 16 anni.

## 7. Ordine di lavoro suggerito

1. **Account e dati** — Supabase Auth + RLS, migrazione dello Store dal
   backend `db` a `supabase`. Senza questo, niente ha senso.
2. **Deploy su Hostinger** — dominio, TLS, la SPA servita davvero.
3. **Freemium** — contatori di benchmark, messaggi e file per utente.
4. **Pagamenti** — Stripe Billing + Checkout, poi fatturazione SDI.
5. **Rendering** — Edge Function verso fal.ai, gated su Premium.
6. **Collega la tua AI** — dopo che il resto è solido, mai prima.
7. **Mobile** — Capacitor, quando il web è stabile.
8. **Traduzioni** — file di lingua, poi le lingue oltre l'italiano.

Il tema ricorrente: **niente di quanto sopra dipende dall'artifact.** Il
prototipo continua a funzionare per Raviola 32 mentre il prodotto pubblico
si costruisce accanto.

## 8. Costi fissi minimi per partire

| Voce | Costo |
|---|---|
| Hostinger | già pagato |
| Dominio | ~10-15 €/anno |
| Supabase | $0 fino ai limiti del free, poi $25/mese |
| Stripe | nessun fisso, solo commissioni sulle transazioni |
| Fatture in Cloud | da verificare sul piano scelto |
| Claude API | a consumo |
| fal.ai | a consumo |
| Apple Developer | $99/anno (solo con l'app) |
| Google Play | $25 una tantum (solo con l'app) |

Si può arrivare online con il web a **poche decine di euro l'anno** più i
consumi. Le app mobili sono la voce che alza la soglia fissa.
