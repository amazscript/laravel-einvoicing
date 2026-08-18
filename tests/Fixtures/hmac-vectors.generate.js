// Génère des vecteurs de référence en appliquant à la lettre l'algorithme publié
// par Iopole (docs.iopole.com, section "HMAC Authentication"). Le code de calcul
// ci-dessous est repris de leur propre snippet de vérification.
const crypto = require('crypto');
const fs = require('fs');

const SECRET = 'ac4f8b1e9d2c7a6b5e3f0d8c1a9b7e6d4c2f1a0b9e8d7c6b5a4f3e2d1c0b9a88'; // secret de test, 32 octets hex
const TIMESTAMP = '1755525600';
const METHOD = 'POST';
const PATH_WITH_QUERY = '/einvoicing/webhook?type=invoice';

function sign(bodyForChecksum) {
  const checksum = crypto.createHash('sha256').update(bodyForChecksum).digest('hex');
  const canonical = `${TIMESTAMP}\n${METHOD.toUpperCase()}\n${PATH_WITH_QUERY}\n${checksum}`;
  const signature = crypto.createHmac('sha256', SECRET).update(canonical).digest('hex');
  return { checksum, canonical, signature };
}

// ---- cas 1 : statut en application/json, checksum sur le corps entier
const jsonBody = JSON.stringify({
  invoiceId: '29d2576d-8408-4248-93b9-ca05251b4ce0',
  statusId: '1f102a32-b0c4-4eba-a31d-0be0f416bf07',
  date: '2024-07-16T20:44:12Z',
  destType: 'OPERATOR',
  status: { code: 'RECEIVED', value: '202', desc: 'Reçue par la plateforme' },
});
const jsonCase = { name: 'json', contentType: 'application/json', body: jsonBody, ...sign(jsonBody) };

// ---- cas 2 : facture en multipart, checksum sur le CONTENU DU FICHIER SEUL.
// Le piège : les champs annexes sont exclus du calcul, alors qu'ils sont bien
// présents dans le corps transmis.
const BOUNDARY = '----IopoleBoundaryTest01234';
const fileContent = '<?xml version="1.0" encoding="UTF-8"?>\n<Invoice><ID>F-2026-0042</ID></Invoice>';
const extraFields = { invoiceId: 'abc-123', format: 'FACTURX', direction: 'INBOUND' };

let multipart = '';
for (const [k, v] of Object.entries(extraFields)) {
  multipart += `--${BOUNDARY}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`;
}
multipart += `--${BOUNDARY}\r\nContent-Disposition: form-data; name="file"; filename="invoice.xml"\r\n`;
multipart += `Content-Type: application/xml\r\n\r\n${fileContent}\r\n`;
multipart += `--${BOUNDARY}--\r\n`;

const multipartCase = {
  name: 'multipart',
  contentType: `multipart/form-data; boundary=${BOUNDARY}`,
  body: multipart,
  boundary: BOUNDARY,
  fileFieldName: 'file',
  fileContent,
  extraFields,
  ...sign(fileContent),
};

// ---- cas 3 : le même multipart, mais signé à tort sur le corps entier.
// Sert de contre-exemple : une implémentation qui tombe dans le piège produit
// cette signature, et doit être rejetée.
const wrong = sign(multipart);

const vectors = {
  _source: 'Algorithme publié sur docs.iopole.com — vecteurs générés avec leur snippet Node.js',
  secret: SECRET,
  timestamp: TIMESTAMP,
  method: METHOD,
  pathWithQuery: PATH_WITH_QUERY,
  cases: [jsonCase, multipartCase],
  multipartSignedOverWholeBody: wrong.signature,
};

fs.writeFileSync('vectors.json', JSON.stringify(vectors, null, 2));
console.log('checksum json      :', jsonCase.checksum);
console.log('signature json     :', jsonCase.signature);
console.log('checksum multipart :', multipartCase.checksum, '(fichier seul)');
console.log('signature multipart:', multipartCase.signature);
console.log('piège (corps entier):', wrong.signature);
