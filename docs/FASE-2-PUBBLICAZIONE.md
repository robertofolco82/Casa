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

### 4.3 Pagamenti — confronto, non solo Stripe
Aggiornato il 13.09.2026 dopo la domanda di Roberto: esistono alternative
più economiche o più semplici di Stripe?

**Commissioni a confronto su un Premium ipotetico da 9,90 €/mese:**

| Soluzione | Commissione | Su 9,90 € | IVA e fatture estere |
|---|---|---|---|
| Stripe standard | 1,5% + 0,25 € (carte SEE) | 0,40 € — **4,0%** | a carico nostro |
| Mollie | simile su carte; PostePay 1,20% + 0,25 € | ~0,40 € | a carico nostro |
| Stripe Managed Payments | standard + 3,5% | 0,75 € — 7,5% | gestite dalla piattaforma |
| Paddle / Polar / Lemon Squeezy | 5% + $0,50 | ~0,95 € — **9,5%** | gestite dalla piattaforma |

**La quota fissa domina sui piccoli importi.** I 50 centesimi di dollaro dei
merchant of record valgono da soli il 5% di un ticket da 10 €: è il motivo
per cui Paddle costa più del doppio di Stripe su questo prodotto, pur avendo
una percentuale nominale simile.

**Cosa fa un merchant of record** (Paddle, Polar, Lemon Squeezy, Stripe
Managed Payments): diventa il venditore legale al posto nostro. Incassa
l'IVA del paese del cliente, la versa, gestisce i contenziosi. Elimina OSS
e adempimenti esteri — il nostro cliente fiscale diventa uno solo. Per
questo costa 5-9% invece del 4%.

**Decisione: si parte con Stripe standard.** A basso volume l'onere IVA è
gestibile con il commercialista, e il risparmio è circa il 5% su ogni euro
incassato. Un MoR va rivalutato quando le vendite estere diventano
significative — e in quel caso **non Stripe Managed Payments**, che le
fonti indicano come il più caro del mercato: Paddle e Polar costano meno a
parità di servizio. Nota su Polar: la tariffa agevolata 4% + $0,40 è
riservata agli account creati prima del 27 maggio 2026, quindi per noi non
è più disponibile.

Altri dettagli Stripe:
- 1,9% + 0,25 € per carte premium/business SEE; 2,9% + 0,25 € extra-europee.
  Nessun canone fisso né costo di attivazione.
- **Metodi**: carte, wallet (Apple/Google Pay), SEPA. Per **PayPal** va
  verificata l'attivazione sull'account italiano al momento del setup:
  non l'ho confermata in questa ricerca, non darla per scontata. Mollie lo
  supporta nativamente, se diventasse un requisito bloccante.
- **Stripe Billing** per i ricorrenti, **Stripe Tax** per l'IVA estera,
  **Stripe Checkout** ospitato per non far passare mai da noi i dati della
  carta (riduce drasticamente il perimetro PCI).

### 4.3b Il modello di prezzo conta più del gateway

Un fatto del prodotto che cambia i conti: **ristrutturare ha una fine.**
Nessuno usa RAVIOLA32 per sempre, lo usa per 6-18 mesi. Un abbonamento
mensile su questo caso d'uso produce churn fisiologico e 12 transazioni
all'anno per cliente.

| Modello | Transazioni/anno | Commissioni/anno | Incidenza |
|---|---|---|---|
| 9,90 €/mese | 12 | 4,78 € su 118,80 € | 4,0% |
| 99 € una tantum, 12 mesi di accesso | 1 | 1,74 € su 99 € | **1,8%** |

L'una tantum dimezza l'incidenza delle commissioni, incassa subito, elimina
il churn mensile e parla la lingua del cliente: «sto ristrutturando, pago
per il progetto» è più naturale di un abbonamento da ricordarsi di disdire.
Con un ticket da 99 € anche i MoR tornano ragionevoli (5,4%), quindi la
strada internazionale resta aperta.

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

## 5. "Collega la tua AI" — ridimensionata, non eliminata

Aggiornamento del 13.09.2026. La funzione è tecnicamente fattibile appena
esistono le Edge Function: non era quello il limite. Il problema è di
mercato, ed è dirimente.

**Un abbonamento Claude Pro o ChatGPT Plus non è una chiave API.** Sono
prodotti separati, con fatturazione separata. Chi dice «ho l'abbonamento a
ChatGPT» quasi sempre **non ha niente da collegare**: dovrebbe aprire un
account sulla console per sviluppatori, generare una chiave e inserire una
carta a consumo. È una nicchia tecnica, non il pubblico di questo servizio.

Conseguenze pratiche:
- Resta come *perk* Premium per l'utente tecnico, costruita per ultima.
- **Non è la leva per contenere i costi del piano gratuito.** Le leve vere
  sono il modello giusto per ogni compito (classificare un PDF non richiede
  il modello più potente) e il prompt caching, dato che il contesto della
  casa si ripete identico a ogni messaggio.

Se e quando si costruisce, custodire la chiave di un altro è una
responsabilità seria: se trapela, paga lui. Regole non negoziabili:

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
