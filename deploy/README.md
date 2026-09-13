# Mettere online il prototipo su Hostinger

Obiettivo di questa fase: un indirizzo pubblico dove il servizio funziona
davvero — benchmark, chat, planimetria, archivio — **senza attivare ancora
nessun servizio a pagamento** oltre alla chiave Claude.

Cosa **non** c'è ancora, di proposito: account e login, pagamenti, rendering
degli ambienti, sincronizzazione fra dispositivi. Arrivano dopo, quando il
cuore del servizio avrà superato la prova sul campo.

---

## La regola che non si viola mai

**La chiave API non entra nel browser.** Mai.

Una pagina HTML è pubblica per definizione: chiunque apre il sorgente e legge
tutto quello che c'è dentro. Se la chiave finisse lì, in poche ore qualcuno la
troverebbe — esistono robot che scandagliano il web esattamente per questo — e
la spesa la pagheresti tu.

Per questo la chiave sta in `api/config.php`, un file PHP che il server esegue
e non mostra mai. Il browser parla con `api/claude.php`, e quel file parla con
Anthropic. La chiave non attraversa mai la rete verso l'utente.

**Non mandarla in chat, nemmeno a me.** La scrivi direttamente sul server:
è l'unico posto dove serve.

---

## Cosa serve

- Un piano di hosting Hostinger (va bene anche il più economico: serve solo
  PHP, attivo di serie su tutti i piani Web e Cloud)
- Un dominio o un sottodominio
- Una chiave API di Anthropic, creata su `console.anthropic.com`

Nota: **la chiave API non è l'abbonamento Claude Pro.** Sono due prodotti
separati, con fatturazione separata. La chiave si paga a consumo.

---

## Passi

### 1. Prepara i file

```bash
./deploy/build.sh
```

Produce la cartella `dist/`:

```
dist/
  index.html            l'applicazione
  api/claude.php        il ponte verso Claude
  api/config.example.php modello di configurazione
  api/.htaccess         nega l'accesso web a config.php
```

### 2. Carica su Hostinger

Da hPanel → **Gestore file**, entra in `public_html/` e carica il contenuto
di `dist/` (non la cartella, il contenuto). Alla fine deve esserci
`public_html/index.html` e `public_html/api/claude.php`.

### 3. Configura la chiave, sul server

Sempre dal Gestore file, dentro `public_html/api/`:

1. copia `config.example.php` in `config.php`
2. aprilo e compila due campi:

```php
'api_key'         => 'sk-ant-...',        // la tua chiave
'codice_accesso'  => 'una-frase-lunga',   // lo scegli tu
```

Il **codice d'accesso** è la seconda difesa: senza, chiunque trovi
l'indirizzo potrebbe usare il tuo credito. I tester lo digitano una volta,
resta salvato nel loro browser.

### 4. Prova

Apri il sito. In **Impostazioni** deve comparire *«Attivo attraverso il
server»*. Inserisci il codice e fai una domanda nella chat: se risponde,
il giro è chiuso.

Se leggi *«Manca config.php sul server»*, il passo 3 non è andato a buon fine.

---

## Quanto costa tenerlo acceso

Solo il consumo della chiave, a token. Le due difese che contano:

- **`limite_richieste`** in `config.php`: 30 richieste ogni 10 minuti per
  indirizzo IP. Regge una sessione di prova vera e ferma uno script.
- **`codice_accesso`**: senza, non passa nulla.

Se sospetti che la chiave sia trapelata, **revocala dalla console** e
mettine una nuova. È l'unica azione che conta: cambiare il codice
d'accesso non basta.

---

## Come sono messi i dati in questa fase

Ogni visitatore ha il suo archivio **nel proprio browser** (localStorage).
Conseguenze da conoscere prima di far provare il sito a qualcuno:

- i dati **non** si spostano fra dispositivi
- svuotare i dati del browser **cancella tutto**
- tu non vedi cosa hanno inserito i tester

È esattamente ciò che serve per provare il cuore del servizio senza
database. Quando serviranno account veri e sincronizzazione, si passa a
Supabase cambiando un solo adattatore — le viste non se ne accorgono.

---

## Dove gira l'app, e con che differenze

| | Artifact (claude.ai) | Hostinger |
|---|---|---|
| Indirizzo | privato | pubblico |
| Modello AI | incluso nella vista | la tua chiave, via `api/claude.php` |
| Costo dell'AI | abbonamento Claude | a consumo |
| Dati | database dell'artifact | browser di chi visita |
| Google Drive | funziona | disattivato |
| Codice d'accesso | non serve | obbligatorio |

**È lo stesso identico file.** L'app capisce da sola dove si trova: se esiste
il modello della vista lo usa, altrimenti cerca il ponte sul server, e se non
trova nemmeno quello continua a funzionare senza AI — i benchmark si creano
comunque e restano in coda.
