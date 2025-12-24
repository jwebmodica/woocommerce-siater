=== Siater Connector ===
Contributors: jweb, sicilwareinformatica
Tags: woocommerce, sia, gestionale, sync, import
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sincronizza prodotti tra WooCommerce e il gestionale SIA (Sicilware Informatica).

== Description ==

Siater Connector permette di sincronizzare automaticamente i prodotti dal gestionale SIA al tuo negozio WooCommerce.

= Caratteristiche Principali =

* **Importazione Prodotti Semplici**: Importa prodotti senza varianti (es. bottiglie di vino)
* **Importazione Prodotti Variabili**: Importa prodotti con taglie e colori come varianti
* **Sincronizzazione Prezzi**: Supporto per listini multipli, IVA, sconti e arrotondamenti
* **Gestione Giacenze**: Sincronizza le quantita di magazzino
* **Importazione Immagini**: Importa immagini prodotto e galleria
* **Immagini Varianti**: Supporto per immagini specifiche per ogni variante
* **Esportazione Ordini**: Esporta gli ordini in formato CSV per SIA
* **Categorie Gerarchiche**: Crea automaticamente le categorie prodotto
* **Integrazione Brand**: Supporta Perfect Brands for WooCommerce

= Requisiti =

* WordPress 6.0+
* PHP 7.4+
* WooCommerce 7.0+
* Perfect Brands for WooCommerce (opzionale, per i brand)
* Gestionale SIA con accesso RSS

= Configurazione Cron =

Per la sincronizzazione automatica, configura questi cron job:

**Sincronizzazione Prodotti (ogni 15 minuti):**
`wget --quiet --delete-after "https://tuosito.it/siater-sync/?authkey=TUO_AUTH_KEY"`

**Esportazione Ordini (ogni 30 minuti):**
`wget --quiet --delete-after "https://tuosito.it/siater-export/?authkey=TUO_AUTH_KEY"`

== Installation ==

1. Carica la cartella `siater` nella directory `/wp-content/plugins/`
2. Attiva il plugin dal menu 'Plugin' di WordPress
3. Vai su Siater > Impostazioni
4. Inserisci il codice licenza e nome utente
5. Configura l'URL del sistema SIA e le opzioni desiderate
6. Configura i cron job per la sincronizzazione automatica

== Changelog ==

= 3.0.0 =
* Rinominato plugin da "Siater Connector 2026" a "Siater Connector"
* Namespace aggiornato da Siater2026 a Siater
* Slug admin semplificato (siater invece di siater-2026)
* Pulizia codice e standardizzazione naming

= 1.0.0 =
* Prima versione del plugin completamente riscritto
* Architettura moderna PHP 7.4+ con namespace
* Usa SKU prodotto per identificazione invece di tabella matrice
* Sistema di lock migliorato per sincronizzazioni
* Logger integrato con rotazione automatica
* Supporto HPOS per esportazione ordini
* Interfaccia admin migliorata
* Gestione memoria ottimizzata

== Upgrade Notice ==

= 3.0.0 =
Aggiornamento naming del plugin. Rinominare la cartella del plugin in "siater" dopo l'aggiornamento.

= 1.0.0 =
Nuova versione completamente riscritta. Backup consigliato prima dell'aggiornamento.
