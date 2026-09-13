# Mettere online RAVIOLA32 su Vercel

Questa è **la strada scelta**. La guida per Hostinger resta in
[README.md](README.md) come alternativa: le due pubblicazioni sono
equivalenti per l'utente e la pagina funziona su entrambe senza modifiche.

Obiettivo: un indirizzo pubblico dove il servizio funziona davvero —
benchmark, chat, planimetria, archivio — **senza attivare ancora nessun
servizio a pagamento** oltre alla chiave Claude.

Cosa **non** c'è ancora, di proposito: account e login veri, pagamenti,
rendering degli ambienti, sincronizzazione fra dispositivi. Le schermate ci
sono e sono quelle definitive; dietro non c'è ancora il motore. Arrivano
dopo, quando il cuore del servizio avrà superato la prova sul campo.

---

## La regola che non si viola mai

**La chiave API non entra nel browser.** Mai.

Una pagina HTML è pubblica per definizione: chiunque apre il sorgente e legge
tutto quello che c'è dentro. Se la chiave finisse lì, in poche ore qualcuno la
troverebbe — esistono robot che scandagliano il web esattamente per questo — e
la spesa la pagheresti tu.

Per questo la chiave sta in una **variabile d'ambiente di Vercel**, che vive
sul server e non viene mai servita al browser. La pagina parla con
`/api/claude`, e quella funzione parla con Anthropic.

**Non mandarla in chat, nemmeno a me.** La incolli nel pannello di Vercel:
è l'unico posto dove serve.

---

## Perché Vercel e non Hostinger

| | Hostinger | Vercel |
|---|---|---|
| Il ponte AI | file PHP che carichi a mano | funzione JavaScript, presa dal repository |
| Aggiornare l'app | ricarichi `index.html` via FTP | `git push`, e il sito si aggiorna da solo |
| Anteprime | una sola, quella pubblica | un indirizzo di prova per ogni commit |
| HTTPS | da configurare | incluso |
| Costo per questa fase | hai già il piano | gratis (piano Hobby) |

Hostinger resta utile per una cosa, e non è poco: **è lì che compri e gestisci
il dominio**. Il dominio lo punti su Vercel (passo 5) e continui a rinnovarlo
dove sei già cliente.

---

## Cosa serve

- Un account Vercel (gratuito) collegato a GitHub → `vercel.com/signup`
- Il repository `robertofolco82/Casa` su GitHub
- Una chiave API di Anthropic, creata su `console.anthropic.com`

Nota: **la chiave API non è l'abbonamento Claude Pro.** Sono due prodotti
separati, con fatturazione separata. La chiave si paga a consumo.

Prima ancora di pubblicare, sulla console Anthropic: **Settings → Limits →
imposta un tetto di spesa mensile.** È la difesa che vale più di tutte le
altre messe insieme, perché è l'unica che non dipende dal codice.

---

## Passi

### 1. Importa il repository

Su `vercel.com/new` → **Import Git Repository** → scegli `Casa`.

Vercel legge `vercel.json` e si configura da solo:

| Campo | Valore | Da dove viene |
|---|---|---|
| Framework Preset | Other | `vercel.json` |
| Build Command | `bash deploy/build.sh vercel` | `vercel.json` |
| Output Directory | `dist` | `vercel.json` |

Non toccare niente. **Non premere ancora Deploy**: prima le variabili.

### 2. Le due variabili d'ambiente

Nella stessa schermata, sezione **Environment Variables** (o dopo, da
Settings → Environment Variables):

| Nome | Valore | A cosa serve |
|---|---|---|
| `ANTHROPIC_API_KEY` | `sk-ant-...` | la chiave. Incollala qui e in nessun altro posto |
| `RAVIOLA_CODICE_ACCESSO` | una frase lunga a tua scelta | il codice che i tester digitano una volta |

Spuntale per tutti e tre gli ambienti (Production, Preview, Development).

Il **codice d'accesso** serve a non lasciare un rubinetto AI aperto su
internet: senza, chiunque trovi l'indirizzo può consumare il tuo credito.
Scegline uno lungo e non indovinabile — non è una password da ricordare, la
digiti una volta per dispositivo e resta salvata.

Facoltative, se un giorno vuoi cambiare qualcosa senza toccare il codice:

| Nome | Default |
|---|---|
| `RAVIOLA_MODELLO_DEFAULT` | `claude-opus-5` |
| `RAVIOLA_MODELLO_QUICK` | `claude-haiku-4-5` |
| `RAVIOLA_MAX_TOKENS_DEFAULT` | `8000` |
| `RAVIOLA_MAX_TOKENS_QUICK` | `4000` |
| `RAVIOLA_LIMITE_RICHIESTE` | `30` |
| `RAVIOLA_LIMITE_FINESTRA` | `600` (secondi) |
| `RAVIOLA_MAX_CARATTERI` | `60000` |

### 3. Deploy

Premi **Deploy**. Dopo un minuto hai un indirizzo tipo
`casa-xxxx.vercel.app`. Da qui in avanti **ogni `git push` sul ramo
principale ripubblica il sito da solo**: non dovrai più caricare file a mano.

### 4. Verifica che il ponte sia vivo

Apri `https://iltuoindirizzo.vercel.app/api/claude` nel browser. Deve
rispondere:

```json
{"errore":"metodo","messaggio":"Usa POST."}
```

Sembra un errore ed è invece la risposta giusta: è la sonda che la pagina usa
per capire se il ponte c'è, e **non consuma un solo token**. Se invece leggi
`non_configurato` o `chiave_mancante`, la variabile d'ambiente non è arrivata:
controlla di averla salvata e rifai il deploy (Deployments → ⋯ → Redeploy).

Poi apri il sito: la prima volta che usi una funzione AI ti viene chiesto il
codice d'accesso.

### 5. Il tuo dominio, da Hostinger

Quando comprerai il dominio (o se ne hai già uno su Hostinger):

1. Su Vercel: **Settings → Domains → Add**, scrivi il dominio.
   Vercel ti mostra i record DNS da creare.
2. Su Hostinger: **Domini → DNS / Nameserver → Gestisci record DNS**,
   e aggiungi quello che Vercel ti ha indicato. Di norma:
   - dominio nudo (`raviola32.it`) → record **A** verso `76.76.21.21`
   - `www` → record **CNAME** verso `cname.vercel-dns.com`
   *(usa sempre i valori che ti mostra Vercel, non questi: possono cambiare)*
3. Aspetta la propagazione (da pochi minuti a qualche ora). Il certificato
   HTTPS Vercel lo emette da solo.

---

## Quanto costa

| Voce | Costo |
|---|---|
| Vercel piano Hobby | 0 € — basta e avanza per un prototipo |
| Chiave Anthropic | a consumo: qualche decina di centesimi per sessione di benchmark |
| Dominio | 10–15 €/anno su Hostinger |
| Hosting Hostinger | quello che già paghi, e serve solo per il dominio |

Il piano Hobby di Vercel è per uso non commerciale. Nel momento in cui
RAVIOLA32 comincerà a vendere qualcosa, serve il piano Pro (20 $/mese per
utente). Per la fase di prova non è un problema.

---

## Se qualcosa non va

| Sintomo | Causa quasi sempre |
|---|---|
| La pagina si apre ma le funzioni AI non ci sono | il ponte non risponde: prova il passo 4 |
| `non_configurato` | variabile `ANTHROPIC_API_KEY` non salvata, o deploy non rifatto dopo averla aggiunta |
| `chiave_mancante` | la chiave è stata incollata male (deve iniziare con `sk-ant-`) |
| «Codice d'accesso non valido» | `RAVIOLA_CODICE_ACCESSO` diverso da quello che digiti |
| «Troppe richieste» | il limite per IP. Alza `RAVIOLA_LIMITE_RICHIESTE` |
| La chat si interrompe a metà di una risposta lunga | il tetto di 60 secondi della funzione sul piano Hobby |

---

## Cosa ho cercato di non promettere

Il **limite per IP** della funzione serverless vive nella memoria
dell'istanza, non su un disco condiviso: se Vercel avvia più copie della
funzione, ogni copia conta per conto proprio. Rallenta chi martella, non è
una fortezza. Le difese che tengono davvero sono due, e stanno entrambe
fuori dal codice: il **codice d'accesso** e il **tetto di spesa** sulla
console Anthropic. Quando ci sarà l'anagrafica utenti vera (fase 2), il
conteggio passerà al database e diventerà serio.
