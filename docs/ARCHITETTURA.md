# Casa — architettura e decisioni

Aggiornato al 6 settembre 2026. Questo file è l'handoff: chi riprende il
progetto legge solo questo.

---

## 1. Cosa è

Hub per la ristrutturazione e l'arredo dell'appartamento di via Raviola 32
(Mezzocammino, Roma). Fa quattro cose:

1. **Benchmark** di prodotti e servizi da acquistare — la funzione centrale.
2. **Ambienti**: la casa come struttura, con i vincoli dimensionali reali.
3. **Acquisti**: spesa, preferiti, acquistato, plafond bonus mobili.
4. **Documentazione**: planimetrie, atti, fatture, preventivi, indicizzati.

Più una **chat** su due superfici e una **coda dei lavori** che collega la
pagina al mondo esterno.

---

## 2. Fasi

| | Fase 1 (attuale) | Fase 2 |
|---|---|---|
| Runtime | Artifact Claude, file unico | Next.js su Vercel |
| Dati | database dell'artifact | Supabase Postgres |
| File | scheda + link; originale su Drive | Supabase Storage + Drive |
| Modello | `sample`, tre livelli | API, modelli per nome |
| Ricerca web | **non esiste nella pagina** | `web_search` server-side |
| Accesso | solo il proprietario, tutti i suoi dispositivi | login, più utenti |

### Perché la fase 1 non è pubblicamente condivisibile

Un artifact che dichiara `db` o `mcp` diventa interno all'organizzazione:
ogni lettore deve essere autenticato. Non è una scelta, è il contratto del
runtime. La scelta reale era fra *dati persistenti* e *link pubblico*.
Abbiamo scelto i dati: senza di essi la coda dei lavori non funziona e
l'app è vuota su ogni nuovo dispositivo.

### Perché la ricerca web non è nella pagina

Il modello raggiungibile dall'artifact non naviga e non ha strumenti
oltre alle funzioni della pagina. Non è una questione di piano di
abbonamento. Di conseguenza i prezzi generati in pagina sono **stime da
conoscenza del modello** e vanno sempre verificati: l'app lo dichiara
esplicitamente nel campo `daVerificare` di ogni candidato.

---

## 3. Lo strato dati

`Store` è un adattatore con un'interfaccia sola:

    Store.get(path)        Store.set(path, data)
    Store.del(path)        Store.list(collection)

Tre implementazioni previste, una attiva alla volta:

- `db` — database dell'artifact. Attivo se `claude.use("db")` risolve.
- `local` — `localStorage`. Fallback automatico.
- `supabase` — fase 2. Stessi path, stessi metodi.

**Nessuna vista conosce il backend.** Il passaggio alla fase 2 tocca
`Store` e nient'altro.

### Path

    app/config            pesi dei criteri, tema, livello del modello
    ambienti/{id}         nome, note, budget
    benchmark/{id}        meta + candidati[] + chat[]
    documenti/{id}        scheda estratta, testo, hash, link, superato
    cartelle/{id}         nome
    coda/{id}             job per la ricerca reale
    chat/globale          conversazione dell'assistente

Limiti del backend `db`: 5.000 documenti per artifact, 256 KiB per
documento. Per questo i messaggi di chat vivono **dentro** il benchmark
(ultimi 60) e non come documenti singoli.

### Schema benchmark

    { titolo, tipo, categoria: prodotto|servizio, ambienti: [id],
      esigenza, vincoli, budget, data, stato, bonusMobili,
      modelliUtente,
      candidati: [{ id, marca, modello, prezzo, venditore, garanziaMesi,
                    specs: [{k,v}], punteggi: {criterio: 0-10},
                    tco: {kwhAnno, costoKwh, anniVita},
                    pro: [], contro: [], fonti: [], daVerificare,
                    preferito, acquistato, prezzoPagato, dataAcquisto,
                    link: [url forniti dall'utente] }],
      chat: [{r: me|ai|err, t, d}] }

Stati: `bozza` → `attesa` (in coda) → `corso` → `fatto`.

Il punteggio è la media ponderata dei criteri valorizzati; i criteri
senza valore non entrano nel calcolo. I pesi si cambiano in Impostazioni
e ricalcolano tutta la classifica.

---

## 4. La coda dei lavori — il pezzo che rende scalabile il resto

La pagina non può navigare né leggere URL. Invece di fingere, scrive un
job:

    { tipo, stato: nuovo|preso|fatto, creato, ref, titolo, ...payload }

Tipi previsti:

| tipo | payload | chi lo esegue |
|---|---|---|
| `benchmark-ricerca-reale` | richiesta completa | oggi io, domani un cron |
| `leggi-link` | url dell'utente | idem |
| `indicizza-documento` | id del documento | idem |

**Fase 1**: leggo la coda con `read_db`, eseguo con la ricerca web vera,
riscrivo i candidati con `write_db`. L'utente vede comparire i dati
nell'app senza copiare niente.

**Fase 2**: un route handler su Vercel legge la stessa tabella, chiama
l'API con `web_search_20260209` e scrive lo stesso JSON. **Il frontend
non cambia di una riga.** Questo è il senso della coda: il contratto è
identico, cambia solo chi lo onora.

### Gerarchia delle fonti per la ricerca reale

Ereditata dalla v1 e confermata:

1. Test di laboratorio indipendenti (Altroconsumo, Stiftung Warentest, Which?).
2. Indagini di affidabilità e tassi di guasto per marca.
3. Discussioni su Reddit — in Italia è la piattaforma in maggiore crescita.
   Se il thread è estero, verificare che il codice modello sia quello IT.
4. Recensioni negative recenti (1-2 stelle, ultimi 12 mesi): i pattern nei
   reclami, non la media stellare.
5. Trustpilot **solo** per giudicare il venditore, mai il prodotto.

Da evitare: classifiche affiliate e siti di comparazione automatica.

---

## 5. Documenti: come si risparmiano i token

    upload → SHA-256 → estrazione testo → scheda strutturata → archivio

- **Estrazione**: `pdf.js` da cdnjs per i PDF, decodifica diretta per
  testo e CSV. Se fallisce, il documento entra in coda con stato
  `da-indicizzare` invece di mentire.
- **Scheda**: una sola chiamata al modello, livello rapido. Produce tipo,
  fornitore, oggetto, imponibile, IVA, totale, data, scadenza, riassunto
  e parole chiave.
- **La chat legge la scheda, mai il file.** Una richiesta ripetuta sullo
  stesso preventivo costa quanto la prima riga di contesto: zero
  rielaborazione.
- **Deduplica**: hash identico → il file viene scartato.
- **Obsolescenza**: stesso fornitore e stesso oggetto, data più recente →
  il precedente passa a `superato`, resta consultabile ma esce dal
  contesto della chat. Niente preventivi zombie nelle risposte.

Il file originale **non** viene caricato: resta su Drive o su disco, e il
documento ne conserva il link. In fase 2 diventa un upload vero su
Supabase Storage con copia su Drive.

---

## 6. Chat

Due superfici, una implementazione (`Chat` + `chatHTML`):

- **incorporata** nel benchmark, contesto = i candidati con specifiche,
  pro, contro e link dell'utente. Salvata in `benchmark/{id}.chat`.
- **overlay globale**, presente su ogni pagina, contesto = ambienti,
  benchmark archiviati in forma compatta, schede dei documenti non
  superati, stato della spesa. Salvata in `chat/globale`.

L'overlay non perde il contesto navigando perché l'applicazione è a
pagina singola: il documento non viene mai ricaricato.

Contesto limitato a 14.000 caratteri, ultimi 14 turni inviati, ultimi 60
messaggi conservati. Il livello del modello (`quick` / `default` /
`complex`) si sceglie dall'intestazione della chat.

---

## 7. Interfaccia

Ispirata ai cataloghi di interior design: serif display in
Cormorant Garamond, sans geometrico maiuscolo e spaziato in Jost, palette
calda (avorio, sabbia, cuoio, verde bosco), regole sottili, molto respiro.

**La CSP dell'artifact blocca ogni immagine esterna**: niente fotografia.
Il registro visivo è quindi tipografico, con illustrazioni line-art in SVG
inline (`ICONE`, scelte da `iconaPer()` sul nome del prodotto).

Tema chiaro e scuro, entrambi definiti su token; responsive testato a
390 px senza scorrimento orizzontale.

---

## 8. Da fare

1. **Consumare la coda**: eseguire i job `benchmark-ricerca-reale` con la
   ricerca web e riscrivere i candidati.
2. **Fase 2**: progetto Supabase, schema relazionale, `Store.supabase`,
   route handler per la coda, deploy Vercel, login per due utenti.
3. **Upload reale dei file** su Supabase Storage con copia su Drive.
4. **Riverifica prezzi in blocco** per i candidati più vecchi di 30 giorni.
5. **Interruzione del benchmark** in corso (oggi si può fermare solo la chat).

## 9. Contratto con l'utente

Italiano, diretto, critico. Nessuna adulazione, nessun padding, nessuna
promessa fumosa. Non ripetere le sue parole come fossero intuizioni.
Informazioni solo verificate: ammettere ciò che non è possibile invece di
aggirarlo. La cosa che chiede per prima è quella che deve funzionare per
prima.

---

## 10. Supabase — progetto e schema (creato il 6 settembre 2026)

    progetto  Casa
    ref       rusiwikqzxzyjrnihrgm
    regione   eu-west-1 (Irlanda)
    postgres  17.6

Schema applicato con la migrazione `20260906_schema_iniziale.sql`, che vive
nel repository ed è la fonte di verità: ogni modifica futura si fa come
nuova migrazione lì dentro, mai a mano dalla dashboard.

Nove tabelle: `ambienti`, `cartelle`, `benchmark`, `benchmark_ambienti`,
`candidati`, `messaggi`, `documenti`, `coda`, `config`. RLS attiva su
tutte, nessun accesso anonimo: il frontend legge come utente autenticato,
i job della coda girano server-side con la service role.

Ricerca full text in italiano sui documenti: colonna generata `tsv` su
nome, fornitore, oggetto, riassunto e testo estratto, con indice GIN.
È questa la ragione principale per cui Supabase batte un foglio Drive.

### Vincolo da tenere presente

**L'artifact non potrà mai parlare con Supabase**: la CSP del runtime
blocca ogni chiamata di rete verso host esterni. Supabase entra in gioco
solo con la fase 2, quando l'app esce dall'artifact e gira su Vercel.
Fino ad allora lo schema resta pronto ma vuoto, e la migrazione dei dati
dal database dell'artifact si fa con un'esportazione una tantum.

---

## 11. Perché il modello della pagina non cerca — e come lo abbiamo aggirato

Due Claude diversi, e la differenza non è il piano di abbonamento.

| | Claude dentro la pagina (`sample`) | Claude in sessione (Claude Code) |
|---|---|---|
| Cosa è | un endpoint di completamento testo | un agente con una cassetta degli attrezzi |
| Strumenti | solo funzioni della pagina | WebSearch, WebFetch, file, database |
| Rete | **bloccata dalla CSP del runtime** | aperta |
| Sa cosa è in commercio oggi | no, si ferma alla data di addestramento | sì, lo va a leggere |

Il piano Pro paga il cervello, non l'attrezzatura. Anche scrivendo un client
di ricerca dentro la pagina, la CSP dell'artifact blocca ogni richiesta di
rete verso qualunque host: è il browser a impedirlo, non una policy che si
possa disattivare.

**Conseguenza di progetto**: alla pagina è vietato nominare prodotti.
`preparaGriglia()` chiede al modello ciò che sa davvero e che non invecchia —
quali specifiche confrontare, cosa chiarire prima di comprare, quali trappole
evitare, quali fasce di prezzo. I nomi dei modelli entrano solo dalla ricerca
reale. Il contesto della chat contiene un divieto esplicito e la data di oggi.

### Come si svuota la coda: a richiesta, mai in background

**Il servizio è pull, non push.** La coda si esegue quando Roberto lo chiede,
in una sessione Claude Code aperta da lui:

    "esegui la coda"

Io leggo `coda` con `read_db`, faccio la ricerca web vera sui job in stato
`nuovo`, riscrivo i candidati nel benchmark e chiudo i job. Costo: zero
quando nessuno chiede niente.

Una Routine oraria che sveglia una sessione per controllare una coda quasi
sempre vuota è stata creata e subito cancellata: 24 risvegli al giorno per
un servizio interrogato qualche volta a settimana sono spreco puro. Non
c'è nessun requisito di tempo reale — un benchmark non scade in un'ora.

Vale anche per la fase 2: **niente cron su Vercel.** La coda si svuota
quando il frontend chiama l'endpoint, cioè quando Roberto preme il pulsante.
Un processo periodico che gira a vuoto costa e non serve a nessuno.



---

## 12. L'ibrido: Drive dentro l'artifact (6 settembre 2026)

Idea di Roberto, e funziona. La pagina **non** può chiamare Google Drive via
rete — la CSP la blocca come tutto il resto — ma può chiamare i **connettori
di chi guarda** attraverso il runtime di Claude. Non è una richiesta di rete:
è un ponte, e passa.

Verificato in sessione prima di scrivere una riga di codice:

| Operazione | Strumento | Esito |
|---|---|---|
| Creare una cartella | `create_file` mime folder | id + viewUrl |
| Caricare un PDF binario | `create_file` + `base64Content` | 640 byte, mime conservato |
| Spostare fra cartelle | `update_file` + `parentId` | nuovo parent |
| Rileggere il contenuto | `download_file_content` | base64 identico |

### Struttura creata su Drive

    Casa — Via Raviola 32          1GXcQFlj2RfOzpYkEJWvT2jWdL3ipGJrA
      ├── Planimetrie              1-lJuZkKMKvb4yakgL2Icb1x0yXL0erqp
      ├── Atti e contratti         1ysXDg5DChnyozoz0AxVEDdVZLA9wQ2qj
      ├── Fatture                  167GjLS--Cr88MAxVwfXq7CZ18wWjhIcS
      ├── Preventivi servizi       1AzlTc0ma19SPzvvk8DWVxdlswaXr29_Z
      ├── Preventivi prodotti      1pRNsFY7YBQ1mkyMc3_aS8yzQpEno_WqZ
      └── Schede tecniche          1yFUNuGwykBu0DxFIMQZ_UdxvPRmRmPjs

La mappa vive in `app/drive` nel database, non nel codice: si corregge senza
ripubblicare. Il modulo `Drive` gestisce ogni codice di errore con la sua via
d'uscita (riconnetti, aggiungi il connettore, consenti, riprova) — mai un
banner generico. Limite 4 MB per file: oltre, il payload base64 diventa
fragile e l'app lo dice invece di fallire in silenzio.

Su una scrittura fallita **non ritenta da sola**: un rifiuto non è prova che
l'operazione non sia avvenuta. Il documento resta comunque indicizzato
nell'app, con l'errore in chiaro nella riga.

### Perché Supabase NON entra nell'artifact

Il connettore Supabase esiste e la pagina potrebbe chiamarlo. Non lo facciamo:
`execute_sql` darebbe a una pagina web il potere di eseguire SQL arbitrario
sul database, e in cambio di cosa? Il `db` dell'artifact fa già lo stesso
lavoro, senza consenso per chiamata e senza round-trip. Supabase serve in
fase 2, quando a parlarci è un server.

### La divisione del lavoro, definitiva per la fase 1

    ARTIFACT                        QUESTA CHAT (Claude Code)
    ─────────────────────────       ──────────────────────────────
    interfaccia e navigazione       ricerca web vera
    archivio dei dati (db)          lettura dei link forniti
    upload dei file → Drive         indicizzazione pesante
    Q&A sui dati già presenti       scrittura dei risultati nel db
    griglia di valutazione
    coda: scrive i job              coda: esegue i job

    Il ponte è la coda. Roberto preme, poi dice "esegui la coda".
