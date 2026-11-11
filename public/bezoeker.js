// Gedeeld door welkom.html, order.html en scores.html.
// - bezoekerId(): het anonieme token van deze telefoon (localStorage); nergens ingevuld, nergens gecontroleerd.
// - bijnaam(id): een leesbare naam voor het scorebord, afgeleid van het token ("Gouden Monnik 17").
//   Iedereen die dezelfde functie draait, komt bij hetzelfde token op dezelfde naam uit: de server hoeft er
//   niets van te weten en er kan geen grove naam op het zaalscherm komen (vaste woordenlijst).

// localStorage kan ontbreken (privévenster): dan werkt de pagina, maar bewaart niets.
const store = {
  get(name, fallback) { try { return JSON.parse(localStorage.getItem(name)) ?? fallback; } catch (e) { return fallback; } },
  set(name, value) { try { localStorage.setItem(name, JSON.stringify(value)); } catch (e) { /* niet erg */ } },
};

// Geen crypto.randomUUID: dat werkt alleen op https, de Pi serveert http.
function uuid() {
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

function bezoekerId() {
  const id = store.get('visitorId', null) || uuid();
  store.set('visitorId', id);
  return id;
}

const BIJNAAM_BIJVOEGLIJK = ['Blauwe', 'Gouden', 'Vrolijke', 'Stoere', 'Slimme', 'Dappere', 'Zeeuwse', 'Gezellige', 'Rustige', 'Lachende',
  'Snelle', 'Warme', 'Trotse', 'Wijze', 'Dorstige', 'Fleurige', 'Stille', 'Grote', 'Kleine', 'Oude', 'Jonge', 'Koele', 'Zachte', 'Heldere'];
const BIJNAAM_ZELFSTANDIG = ['Monnik', 'Kloosterbier', 'Wijnglas', 'Knoop', 'Vuurtoren', 'Kreeft', 'Mossel', 'Meeuw', 'Pater', 'Abt',
  'Brouwer', 'Pelgrim', 'Kaars', 'Klokkenist', 'Tuinman', 'Schipper', 'Visser', 'Molenaar', 'Kroegbaas', 'Dobbelaar', 'Zeemeeuw', 'Bierton', 'Kelder', 'Kruik'];

// FNV-1a: eenvoudig, snel en overal gelijk. Geen beveiliging, alleen verdeling over de lijst.
function bijnaam(id) {
  let h = 0x811c9dc5;
  for (const c of String(id)) { h = Math.imul(h ^ c.charCodeAt(0), 0x01000193) >>> 0; }
  const a = BIJNAAM_BIJVOEGLIJK[h % BIJNAAM_BIJVOEGLIJK.length];
  const n = BIJNAAM_ZELFSTANDIG[Math.floor(h / 24) % BIJNAAM_ZELFSTANDIG.length];
  return a + ' ' + n + ' ' + ((Math.floor(h / 576) % 60) + 1);
}
