/* ═══════════════════════════════════════════════════════════════════════
   RAVIOLA32 — ponte verso l'API di Claude, versione Vercel

   Stesso mestiere del gemello PHP (deploy/api/claude.php) e stesso identico
   contratto verso il browser: il file Ponte dentro casa.html non sa quale
   dei due gli sta rispondendo.

   La differenza è dove abita la chiave. Su Hostinger sta in un file che
   Roberto scrive sul server; qui sta in una variabile d'ambiente che
   Roberto scrive nel pannello di Vercel. In nessuno dei due casi passa
   dal browser, dal repository o da una conversazione.

   Variabili d'ambiente (Vercel → Settings → Environment Variables):
     ANTHROPIC_API_KEY          obbligatoria — la chiave di console.anthropic.com
     RAVIOLA_CODICE_ACCESSO     obbligatoria — il codice che i tester digitano
     RAVIOLA_MODELLO_DEFAULT    opzionale — default claude-opus-5
     RAVIOLA_MODELLO_QUICK      opzionale — default claude-haiku-4-5
     RAVIOLA_MAX_TOKENS_DEFAULT opzionale — default 8000
     RAVIOLA_MAX_TOKENS_QUICK   opzionale — default 4000
     RAVIOLA_LIMITE_RICHIESTE   opzionale — default 30
     RAVIOLA_LIMITE_FINESTRA    opzionale — default 600 (secondi)
     RAVIOLA_MAX_CARATTERI      opzionale — default 60000
   ═══════════════════════════════════════════════════════════════════════ */

import crypto from "node:crypto";

const ENDPOINT     = "https://api.anthropic.com/v1/messages";
const VERSIONE_API = "2023-06-01";

const num = (v, d) => { const n = parseInt(v ?? "", 10); return Number.isFinite(n) ? n : d; };

function stop(res, http, codice, messaggio) {
  res.statusCode = http;
  res.setHeader("Content-Type", "application/json; charset=utf-8");
  res.end(JSON.stringify({ errore: codice, messaggio }));
}

/* ─────────── confronto in tempo costante ───────────
   Passo dagli hash perché timingSafeEqual pretende lunghezze uguali: se
   confrontassi le stringhe grezze, la lunghezza del codice trapelerebbe
   dall'errore. */
function uguali(a, b) {
  const ha = crypto.createHash("sha256").update(String(a)).digest();
  const hb = crypto.createHash("sha256").update(String(b)).digest();
  return crypto.timingSafeEqual(ha, hb);
}

/* ─────────── limite per IP ───────────
   In memoria, non su file: una funzione serverless non ha un disco suo e
   /tmp muore col contenitore. Vale quindi per istanza calda — ferma lo
   script che martella, non è una fortezza. La difesa vera resta il codice
   d'accesso, e il tetto di spesa impostato nella console Anthropic. */
const visite = new Map();
function limitato(ip, max, finestra) {
  if (max <= 0) return false;
  const ora = Date.now(), taglio = ora - finestra * 1000;
  const tempi = (visite.get(ip) || []).filter(t => t > taglio);
  if (tempi.length >= max) { visite.set(ip, tempi); return true; }
  tempi.push(ora);
  visite.set(ip, tempi);
  if (visite.size > 5000) visite.clear();   /* non crescere all'infinito */
  return false;
}

async function corpo(req) {
  if (req.body && typeof req.body === "object") return req.body;   /* Vercel lo pre-parsa */
  let grezzo = "";
  for await (const pezzo of req) {
    grezzo += pezzo;
    if (grezzo.length > 2_000_000) return null;
  }
  try { return JSON.parse(grezzo) } catch { return null }
}

export default async function handler(req, res) {
  const chiave = (process.env.ANTHROPIC_API_KEY || "").trim();
  const atteso = (process.env.RAVIOLA_CODICE_ACCESSO || "").trim();

  if (!chiave)
    return stop(res, 500, "non_configurato",
      "Manca ANTHROPIC_API_KEY fra le variabili d'ambiente di Vercel.");
  if (!chiave.startsWith("sk-ant-"))
    return stop(res, 500, "chiave_mancante",
      "La chiave API non è configurata correttamente sul server.");

  /* Il GET è la sonda gratuita: risponde 405 e non consuma un token.
     Serve alla pagina per capire se il ponte esiste prima di provarci. */
  if (req.method !== "POST") {
    res.setHeader("Allow", "POST");
    return stop(res, 405, "metodo", "Usa POST.");
  }

  const req_ = await corpo(req);
  if (!req_ || typeof req_ !== "object")
    return stop(res, 400, "corpo", "Corpo della richiesta non valido.");

  if (!atteso || !uguali(atteso, String(req_.codice ?? "")))
    return stop(res, 401, "codice", "Codice d'accesso non valido.");

  const max      = num(process.env.RAVIOLA_LIMITE_RICHIESTE, 30);
  const finestra = num(process.env.RAVIOLA_LIMITE_FINESTRA, 600);
  const ip = String(req.headers["x-forwarded-for"] || "").split(",")[0].trim()
          || req.socket?.remoteAddress || "ignoto";
  if (limitato(ip, max, finestra)) {
    res.setHeader("Retry-After", String(finestra));
    return stop(res, 429, "troppe_richieste",
      `Hai superato il limite di ${max} richieste ogni ${Math.floor(finestra / 60)} minuti. Riprova più tardi.`);
  }

  const prompt = String(req_.prompt ?? "").trim();
  if (!prompt) return stop(res, 400, "prompt_vuoto", "Nessun testo da inviare.");

  const maxCar = num(process.env.RAVIOLA_MAX_CARATTERI, 60000);
  if ([...prompt].length > maxCar)
    return stop(res, 413, "prompt_lungo", `Il testo supera i ${maxCar} caratteri consentiti.`);

  const tier    = req_.tier === "quick" ? "quick" : "default";
  const modello = tier === "quick"
    ? (process.env.RAVIOLA_MODELLO_QUICK   || "claude-haiku-4-5")
    : (process.env.RAVIOLA_MODELLO_DEFAULT || "claude-opus-5");
  const maxTok  = tier === "quick"
    ? num(process.env.RAVIOLA_MAX_TOKENS_QUICK,   4000)
    : num(process.env.RAVIOLA_MAX_TOKENS_DEFAULT, 8000);

  /* Le estrazioni JSON tornano in blocco: serve il testo intero per poterlo
     interpretare, lo streaming non aggiungerebbe niente. */
  const vuoleJson = !!req_.json;
  const stream    = !vuoleJson && !!req_.stream;

  const payload = {
    model: modello,
    max_tokens: maxTok,
    messages: [{ role: "user", content: prompt }],
    ...(stream ? { stream: true } : {}),
  };

  let su;
  try {
    su = await fetch(ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-api-key": chiave,
        "anthropic-version": VERSIONE_API,
      },
      body: JSON.stringify(payload),
    });
  } catch (e) {
    return stop(res, 502, "rete", "Non raggiungo il servizio AI: " + (e?.message || "errore di rete"));
  }

  if (stream) {
    if (!su.ok || !su.body) {
      const d = await su.json().catch(() => null);
      return stop(res, su.status >= 500 ? 502 : su.status, "ai_" + su.status,
        messaggioErrore(su.status, d));
    }
    res.statusCode = 200;
    res.setHeader("Content-Type", "text/event-stream; charset=utf-8");
    res.setHeader("Cache-Control", "no-cache, no-transform");
    res.setHeader("X-Accel-Buffering", "no");
    try {
      for await (const pezzo of su.body) res.write(pezzo);
    } catch (e) {
      /* Intestazioni già partite: lo stato HTTP non si può più cambiare,
         l'errore viaggia come evento SSE che il client sa riconoscere. */
      res.write("\nevent: error\ndata: " +
        JSON.stringify({ messaggio: "Connessione interrotta: " + (e?.message || "") }) + "\n\n");
    }
    return res.end();
  }

  const dati = await su.json().catch(() => null);

  if (!su.ok)
    return stop(res, su.status === 401 || su.status === 403 ? 500 : su.status,
      "ai_" + su.status, messaggioErrore(su.status, dati));

  /* Il testo può arrivare spezzato in più blocchi: vanno concatenati tutti,
     non solo il primo. */
  let testo = "";
  for (const blocco of dati?.content || [])
    if (blocco?.type === "text") testo += String(blocco.text ?? "");

  res.setHeader("Content-Type", "application/json; charset=utf-8");
  res.end(JSON.stringify({
    testo,
    uso: {
      input:  dati?.usage?.input_tokens  || 0,
      output: dati?.usage?.output_tokens || 0,
    },
  }));
}

/* Il messaggio di Anthropic è utile in diagnosi ma non deve rivelare nulla
   della configurazione: passa solo il testo, mai la chiave. */
function messaggioErrore(http, dati) {
  const mappa = {
    401: "La chiave API configurata sul server non è valida.",
    403: "La chiave API non è autorizzata per questa operazione.",
    429: "Il servizio AI è momentaneamente sovraccarico di richieste. Riprova fra poco.",
    529: "Il servizio AI è sovraccarico. Riprova fra poco.",
  };
  if (mappa[http]) return mappa[http];
  const d = typeof dati?.error?.message === "string" ? dati.error.message : "";
  return `Il servizio AI ha risposto con errore ${http}` + (d ? ": " + d : ".");
}
