<?php
/* ═══════════════════════════════════════════════════════════════════════
   RAVIOLA32 — configurazione del ponte verso Claude

   COPIA questo file in config.php e compilalo. config.php non entra mai
   nel repository: è nel .gitignore, e deve restare così.

   La chiave sta qui, sul server. Non finisce mai nel browser, non finisce
   mai nel codice della pagina, non finisce mai in una conversazione.
   ═══════════════════════════════════════════════════════════════════════ */

return [

  /* La chiave API di Anthropic. Si crea su console.anthropic.com, non è
     l'abbonamento Claude Pro: è un prodotto separato, a consumo.
     Se pensi che sia trapelata, revocala dalla console e mettine una nuova:
     è l'unica azione che conta davvero. */
  'api_key' => 'sk-ant-...',

  /* Codice d'accesso che i tuoi tester devono digitare una volta.
     Serve a non lasciare un rubinetto AI aperto su internet: senza questo,
     chiunque trovi l'indirizzo può consumare il tuo credito.
     Scegline uno lungo e non indovinabile. */
  'codice_accesso' => 'cambiami-subito',

  /* Modelli. 'default' è quello del benchmark e della chat, 'quick' serve
     per i compiti meccanici (classificare un documento, estrarre campi). */
  'modelli' => [
    'default' => 'claude-opus-5',
    'quick'   => 'claude-haiku-4-5',
  ],

  /* Tetto di token in uscita per risposta. Alzalo se le risposte vengono
     tagliate a metà, abbassalo per contenere la spesa. */
  'max_tokens' => [
    'default' => 8000,
    'quick'   => 4000,
  ],

  /* Limite per indirizzo IP: quante richieste in quanti secondi.
     30 richieste ogni 10 minuti regge una sessione di prova vera e
     ferma uno script che prova ad abusarne. */
  'limite_richieste' => 30,
  'limite_finestra'  => 600,

  /* Tetto di caratteri in ingresso: una difesa grossolana ma efficace
     contro chi prova a mandare un libro intero per bruciare credito. */
  'max_caratteri_prompt' => 60000,
];
