# SDK del Servidor PIM v1.0

Bibliotecas de un solo archivo, sin dependencias, para que otros sistemas trabajen con el Servidor PIM (protocolo PIM v0.9 sobre HL7 FHIR R4).

| Archivo | Para |
|---|---|
| `php/PimCliente.php` | PHP ≥ 8.1 con openssl y curl (ERP, sistemas web, contratistas) |
| `php/generar-claves.php` | Genera el par de claves del sistema cliente y su `jwks_publico.json` |
| `js/pim-cliente.mjs` | Node.js ≥ 18 (gateways IoT, integraciones, scripts) |

1. `php php/generar-claves.php ./claves mi-sistema-1` → la clave privada queda en tu servidor; entregás solo `claves/jwks_publico.json`.
2. El Gestor del nodo te registra: `iProy-Control pim cliente alta mi-sistema jwks_publico.json <GM|EJ|SO|FT|CI> [organización]`.
3. Usá el SDK con la URL `https://<nodo>:8443/pim/r4`, tu `client_id`, tu clave privada y tu `kid`.

Manual completo: `docs/pim/IPROY-PIM-MAN-001_Manual_Servidor_PIM.pdf` (§5). Contrato: `docs/pim/openapi_pim_v1.0.yaml`.
