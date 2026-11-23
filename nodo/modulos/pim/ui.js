// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo pim · pestañas Órdenes, Nueva solicitud e Inventario (todo por la API PIM con el rol del usuario).
// 1.7.0: Inventario nuevo (establecimiento, árbol de ubicaciones, clase del activo, editar, sacar de circulación, importar y exportar planillas).
// 1.6.0: se elige de listas (activos, organizaciones, tipos EN 13306, modos de falla ISO 14224, motivos, fabricantes, ubicaciones);
//        estados en castellano; formularios plegables «＋ Nuevo …»; códigos de activo y de ubicación sugeridos automáticamente.
(() => {
  const { h, fecha, aviso, tabla, panel, form, pim, puede, seccion, kpis } = Nucleo;
  const ESTADO_TXT = { solicitada: 'Solicitada', 'en-revision': 'En revisión', asignada: 'Asignada', aceptada: 'Aceptada', lista: 'Lista para ejecutar', 'en-ejecucion': 'En ejecución',
    'en-espera': 'En espera', finalizada: 'Finalizada', conformada: 'Conformada', rechazada: 'Rechazada', anulada: 'Anulada' };
  const ESTADO_COLOR = { solicitada: '', 'en-revision': 'ama', asignada: 'ama', aceptada: '', lista: '', 'en-ejecucion': 'nar', 'en-espera': 'gris', finalizada: 'verde', conformada: 'verde', rechazada: 'rojo', anulada: 'gris' };
  const etEstado = (e) => `<span class="et ${ESTADO_COLOR[e] || ''}">${h(ESTADO_TXT[e] || e)}</span>`;
  const PRIO = { stat: ['rojo', 'Inmediata'], asap: ['nar', 'Lo antes posible'], urgent: ['ama', 'Urgente'], routine: ['', 'Rutina'] };
  const prio = (p) => `<span class="et ${(PRIO[p] || [''])[0]}">${h((PRIO[p] || [0, p])[1])}</span>`;
  const est = (t) => t.businessStatus?.coding?.[0]?.code || t.status;
  const SIG = { solicitada: ['en-revision', 'asignada', 'rechazada', 'anulada'], 'en-revision': ['asignada', 'rechazada', 'anulada'], asignada: ['aceptada', 'en-revision', 'anulada'],
    aceptada: ['lista', 'en-ejecucion', 'en-espera', 'anulada'], lista: ['en-ejecucion', 'en-espera', 'anulada'], 'en-ejecucion': ['en-espera', 'finalizada'],
    'en-espera': ['en-ejecucion', 'aceptada', 'anulada'], finalizada: ['conformada', 'en-ejecucion'] };

  Nucleo.tablero(async (el) => {
    const c = async (q) => (await pim('GET', 'Task?' + q + '&_summary=count')).cuerpo.total;
    const [total, abiertas, urg, ejec, fin] = await Promise.all([c(''), c('status=requested,received,accepted,ready,in-progress,on-hold'),
      c('priority=stat,asap&status=requested,received,accepted,ready,in-progress'), c('status=in-progress'), c('status=completed')]);
    el.innerHTML = Nucleo.kpis([{ t: 'Órdenes totales', n: total, sub: 'histórico', icono: 'ordenes', color: '#1e88e5' },
      { t: 'Abiertas', n: abiertas, sub: 'sin cerrar', icono: 'reloj', color: '#fb8c00' },
      { t: 'Urgentes', n: urg, sub: 'inmediatas o ASAP abiertas', icono: 'alarmas', color: '#e53935' },
      { t: 'En ejecución', n: ejec, sub: 'trabajando ahora', icono: 'telemetria', color: '#8e24aa' },
      { t: 'Finalizadas', n: fin, sub: 'total', icono: 'ok', color: '#43a047' }]);
    el.querySelectorAll('.kpi').forEach((k) => { k.style.cursor = 'pointer'; k.onclick = () => Nucleo.ir('ordenes'); });
  }, { id: 'pim.ordenes', titulo: 'Órdenes de trabajo' });

  Nucleo.pestana({ id: 'ordenes', titulo: 'Órdenes', orden: 10, cap: 'pim.usar', render: async (el) => {
    el.innerHTML = `<div class="tarjeta"><div class="filtros"><div style="min-width:200px"><label>Estado</label><select id="oEst"><option value="">Abiertas</option>
      ${Object.keys(SIG).concat(['conformada', 'rechazada', 'anulada']).map((e) => `<option value="${e}">${h(ESTADO_TXT[e] || e)}</option>`).join('')}<option value="*">Todas</option></select></div></div><div id="oLista"></div></div>`;
    const cargar = async () => {
      const e = document.getElementById('oEst').value;
      const q = e === '' ? 'status=requested,received,accepted,ready,in-progress,on-hold' : e === '*' ? '' : 'business-status=' + e;
      const b = (await pim('GET', 'Task?' + q + '&_count=100&_sort=-authored-on')).cuerpo;
      const l = (b.entry || []).map((x) => x.resource);
      document.getElementById('oLista').innerHTML = tabla([{ t: 'Número', v: (t) => t.identifier?.[0]?.value || t.id }, { t: 'Estado', html: true, v: (t) => etEstado(est(t)) },
        { t: 'Prioridad', html: true, v: (t) => prio(t.priority) }, { t: 'Activo', v: (t) => t.focus?.display || '' }, { t: 'Responsable', v: (t) => t.owner?.display || '' },
        { t: 'Descripción', v: (t) => (t.description || '').slice(0, 90) }, { t: 'Alta', v: (t) => fecha(t.authoredOn) }], l, detalle);
    };
    document.getElementById('oEst').onchange = cargar; await cargar();
  } });
  async function detalle(t0) {
    const t = (await pim('GET', 'Task/' + t0.id)).cuerpo; const e = est(t);
    const p = panel('Orden ' + (t.identifier?.[0]?.value || t.id), `<dl><dt>Estado</dt><dd>${etEstado(e)}${t.statusReason?.text ? ' · ' + h(t.statusReason.text) : ''}</dd><dt>Prioridad</dt><dd>${prio(t.priority)}</dd>
      <dt>Activo</dt><dd>${h(t.focus?.display || '—')}</dd><dt>Responsable</dt><dd>${h(t.owner?.display || '—')}</dd><dt>Descripción</dt><dd style="white-space:pre-wrap">${h(t.description || '')}</dd>
      <dt>Versión</dt><dd>${h(t.meta?.versionId)} · ${fecha(t.meta?.lastUpdated)}</dd></dl>
      ${puede('pim.ordenes.operar') && SIG[e] ? `<h3>Cambiar estado</h3>${form([{ n: 'estado', t: 'Nuevo estado', tipo: 'select', op: SIG[e].map((x) => [x, ESTADO_TXT[x] || x]) },
        { n: 'responsable', t: 'Responsable', tipo: 'opciones', cargar: organizaciones, vacio: '— sin cambiar —', ayuda: 'Empresa o área que ejecuta (al asignar)' },
        { n: 'tipoMantenimiento', t: 'Tipo de mantenimiento', tipo: 'lista', lista: 'tipo_mantenimiento', vacio: '— sin cambiar —', ayuda: 'EN 13306' },
        { n: 'modoFalla', t: 'Modo de falla', tipo: 'lista', lista: 'modo_falla', vacio: '—', ayuda: 'ISO 14224 · obligatorio al finalizar un correctivo' }, { n: 'motivo', t: 'Motivo o nota', tipo: 'textarea' }], 'Aplicar', async (d, m) => {
          const par = [{ name: 'estado', valueCode: d.estado }];
          if (d.responsable) { const o = (await pim('GET', 'Organization?identifier=' + encodeURIComponent(d.responsable))).cuerpo.entry?.[0]?.resource; if (!o) throw new Error('No existe la organización ' + d.responsable); par.push({ name: 'responsable', valueReference: { reference: 'Organization/' + o.id } }); }
          if (d.tipoMantenimiento) par.push({ name: 'tipoMantenimiento', valueCode: d.tipoMantenimiento });
          if (d.modoFalla) par.push({ name: 'modoFalla', valueCode: d.modoFalla });
          if (d.motivo) par.push({ name: 'motivo', valueString: d.motivo });
          await pim('POST', `Task/${t.id}/$transicion`, { resourceType: 'Parameters', parameter: par }, { 'If-Match': `W/"${t.meta.versionId}"` });
          aviso(m, 'ok', 'Estado actualizado.'); setTimeout(() => { Nucleo.cerrar(); Nucleo.ir('ordenes'); }, 700);
        })}` : ''}<h3>Historial</h3><div id="oHist" class="mini">…</div>`);
    const hs = (await pim('GET', `Task/${t.id}/_history`)).cuerpo.entry || [];
    p.querySelector('#oHist').innerHTML = tabla([{ t: 'Versión', v: (x) => x.resource.meta.versionId }, { t: 'Fecha', v: (x) => fecha(x.response.lastModified) }, { t: 'Estado', html: true, v: (x) => etEstado(est(x.resource)) }], hs);
  }

  // Activos para elegir en la solicitud: código y nombre (el que pide no conoce los códigos de inventario, 1.1.1)
  const NS = 'https://iproy.com.ar/fhir';
  const nombreDe = (d) => d.deviceName?.[0]?.name || d.modelNumber || d.id;
  const codigoDe = (d) => (d.identifier || []).find((i) => (i.system || '').endsWith('/sid/activo'))?.value || d.identifier?.[0]?.value || '';
  async function activos(n = 500) { return ((await pim('GET', 'Device?_count=' + n)).cuerpo.entry || []).map((x) => x.resource); }
  /** Opciones de activo para elegir: «Nombre · código · ubicación» (quien pide no conoce los códigos de inventario). */
  async function opcionesActivo() {
    // 1.7.0: marca, modelo y número de serie van en el texto, así el buscador de la lista también encuentra por ellos
    return (await activos()).filter((d) => d.status === 'active').map((d) => [d.id, [nombreDe(d), codigoDe(d), [d.manufacturer, d.modelNumber].filter(Boolean).join(' '), d.serialNumber ? 'N.º ' + d.serialNumber : '', d.location?.display].filter(Boolean).join(' · ')]).sort((a, b) => a[1].localeCompare(b[1]));
  }
  async function organizaciones() {
    const l = ((await pim('GET', 'Organization?_count=300')).cuerpo.entry || []).map((x) => x.resource);
    return l.map((o) => [o.identifier?.[0]?.value || o.id, `${o.name || o.id}${o.identifier?.[0]?.value ? ' · ' + o.identifier[0].value : ''}`]).sort((a, b) => a[1].localeCompare(b[1]));
  }
  Nucleo.pestana({ id: 'solicitud', titulo: 'Nueva solicitud', orden: 20, cap: 'pim.solicitar', render: async (el) => {
    setTimeout(() => { PRESEL = ''; }, 0);   // el activo elegido desde su ficha vale para esta vez
    el.innerHTML = `<div class="tarjeta"><h2>Pedir un trabajo</h2>${form([
      { n: 'activo', t: 'Equipo o instalación', tipo: 'opciones', cargar: opcionesActivo, req: true, vacio: '— elegí de la lista —', ayuda: 'Podés buscar por nombre, código, marca, modelo, número de serie o lugar', ancho: 'todo', v: PRESEL },
      { n: 'categoria', t: 'Qué le pasa', tipo: 'lista', lista: 'categoria_solicitud', req: true, otro: false },
      { n: 'prioridad', t: 'Prioridad', tipo: 'select', op: [['', 'Que la calcule el sistema (IRU)'], ['routine', 'Rutina'], ['urgent', 'Urgente'], ['asap', 'Lo antes posible'], ['stat', 'Inmediata']] },
      { n: 'estadoDeclarado', t: 'Estado del equipo', tipo: 'select', op: [['', '— no sé —'], ['operativo', 'Funciona'], ['degradado', 'Funciona con problemas'], ['fuera-de-servicio', 'No funciona']] },
      { n: 'descripcion', t: 'Contá brevemente qué pasa', tipo: 'textarea', req: true, ph: 'Ej.: el equipo no enciende desde esta mañana; huele a quemado.' }], 'Enviar solicitud', async (d, m) => {
        if (!d.activo) throw new Error('Elegí el equipo o la instalación de la lista. Si no está (o está fuera de servicio), pedile a mantenimiento que lo dé de alta o lo reactive en Inventario.');
        if (!d.categoria) throw new Error('Elegí qué le pasa');
        const a = (await pim('GET', 'Device/' + d.activo)).cuerpo;
        const sr = { resourceType: 'ServiceRequest', status: 'active', intent: 'order', subject: { reference: 'Device/' + a.id, display: nombreDe(a) },
          code: { coding: [{ code: d.categoria }], text: Nucleo.textoLista('categoria_solicitud', d.categoria) }, note: [{ text: d.descripcion }], requester: { display: Nucleo.sesion.usuario.nombre } };
        if (d.prioridad) sr.priority = d.prioridad;
        if (d.estadoDeclarado) sr.extension = [{ url: NS + '/StructureDefinition/pim-estado-declarado', valueCoding: { system: NS + '/CodeSystem/pim-valores', code: d.estadoDeclarado } }];
        const r = await pim('POST', 'ServiceRequest', sr);
        // la cabecera X-PIM-Task trae la referencia (Task/<id>): el número a la vista se lee de la orden
        const ref = r.cab.get('X-PIM-Task') || ''; let num = '';
        if (ref) { try { num = (await pim('GET', ref)).cuerpo.identifier?.[0]?.value || ''; } catch (x) { /* sin lectura de órdenes: queda el número de la solicitud */ } }
        aviso(m, 'ok', `Solicitud ${r.cuerpo.identifier?.[0]?.value || r.cuerpo.id} registrada para «${nombreDe(a)}»${num ? `; se generó la orden ${num}` : ref ? '; se generó la orden' : ''}.`);
      })}</div>`;
  } });

  // ---------------------------------------------------------------- Inventario (pim 1.7.0)
  // Establecimiento › edificio › piso › sala, y cada activo con su clase (instalación, equipo médico u otro). Importar y exportar planillas.
  // Nada se borra: un activo se saca de circulación con su motivo y queda en el historial. Cambiar el código no rompe las órdenes:
  // apuntan al activo (no al texto) y cada módulo pone al día sus copias con el evento pim/Device/update.
  const CLASE_TXT = { sistema: 'Instalación', 'equipo-medico': 'Equipo médico', activo: 'Otro activo' };
  const CLASE_AYUDA = { sistema: ['Gases medicinales, electricidad, climatización, agua, vapor…', 'Ej.: Tanque de oxígeno líquido', 'Ej.: tanque criogénico, central de cilindros, tablero'],
    'equipo-medico': ['Monitores, respiradores, bombas de infusión… (N.º ANMAT)', 'Ej.: Monitor multiparamétrico cama 3', 'Ej.: monitor, respirador, desfibrilador'],
    activo: ['Herramientas, mobiliario, vehículos y todo lo demás', 'Ej.: Escalera telescópica', 'Ej.: herramienta, mueble, vehículo'] };
  const EST_INV = { 'en-servicio': ['verde', 'En servicio'], 'fuera-de-circulacion': ['ama', 'Fuera de circulación'], obsoleto: ['gris', 'Obsoleto'], 'cargado-por-error': ['rojo', 'Cargado por error'] };
  const etInv = (e) => `<span class="et ${(EST_INV[e] || ['gris'])[0]}">${h((EST_INV[e] || [0, e])[1])}</span>`;
  const NFPA = [['', '— sin dato —'], ['1', '1 · Lesión grave o muerte'], ['2', '2 · Lesión leve'], ['3', '3 · Molestia, sin lesión'], ['4', '4 · Sin impacto en el paciente']];
  const TIPO_UB = { bu: 'Edificio', wi: 'Ala o pabellón', lvl: 'Nivel o piso', ro: 'Local o sala', area: 'Área o sector', bd: 'Cama' };
  const TIPO_UB_HIJO = { '': 'bu', bu: 'lvl', wi: 'lvl', lvl: 'ro', ro: 'bd', area: 'ro', bd: 'bd' };   // lo que suele ir dentro de cada tipo
  const MAX_ARCHIVO = 1.5 * 1048576;   // el cuerpo de la API admite 2 MB y el archivo viaja en base64
  let INV = null;   // lo último que trajo GET m/pim/inventario
  let PRESEL = '';  // activo elegido desde su ficha para «Pedir un trabajo»
  const traerInv = async () => (INV = await Nucleo.api('GET', 'm/pim/inventario'));
  const raizTxt = () => INV.establecimiento.nombre || INV.establecimiento.sugerido || 'El establecimiento';
  const plural = (n, s, p) => `${n} ${n === 1 ? s : p}`;

  function estilosInv() {
    if (document.getElementById('inv-css')) return;
    const st = document.createElement('style'); st.id = 'inv-css';
    st.textContent = `.inv-estab{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.inv-estab .nom{font-size:19px;font-weight:700;color:var(--az);overflow-wrap:anywhere}
.inv-estab small{display:block;color:var(--gris);font-size:12.5px}
.inv-menu{position:relative}.inv-menu>summary{list-style:none;border:1px solid var(--az3);border-radius:10px;padding:9px 14px;min-height:40px;font-weight:600;cursor:pointer;background:#fff;color:var(--az3);display:inline-flex;align-items:center;gap:6px;box-sizing:border-box}
.inv-menu>summary::-webkit-details-marker{display:none}.inv-menu>summary:hover{background:var(--cel)}
.inv-menu .op{position:absolute;z-index:30;left:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--borde);border-radius:12px;box-shadow:0 8px 24px rgba(16,42,67,.16);padding:6px;width:min(300px,calc(100vw - 32px))}
.inv-menu .op button{display:block;width:100%;text-align:left;border:0;background:none;padding:9px 10px;border-radius:8px;font:inherit;font-weight:600;cursor:pointer;color:#22384d}
.inv-menu .op button:hover{background:var(--cel)}.inv-menu .op small{display:block;color:var(--gris);font-size:12px;font-weight:400}.inv-menu .op hr{border:0;border-top:1px solid var(--borde);margin:4px 0}
.invf .fgrid{grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}.invf .fcampo{grid-column:auto}.invf .fcampo.todo{grid-column:1/-1}.invf .fcampo[hidden]{display:none}
.inv-clases{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}
.inv-clases label{border:1px solid var(--borde);border-radius:12px;padding:10px 12px;cursor:pointer;background:#fff;white-space:normal;display:block}
.inv-clases label.sel{border-color:var(--az3);background:var(--cel);box-shadow:0 0 0 1px var(--az3) inset}
.inv-clases input[type=radio]{width:17px;height:17px;min-height:0;padding:0;margin:0 7px 0 0;vertical-align:-3px;display:inline-block;box-shadow:none;accent-color:var(--az3)}.inv-clases b{color:var(--az)}.inv-clases small{display:block;color:var(--gris);font-size:12px;margin-top:3px;font-weight:400}
.inv-busc input[type=search]{margin-bottom:6px}.inv-nueva{border:1px dashed var(--az3);border-radius:10px;padding:10px;margin-top:8px;background:#fbfdff}
.inv-nueva .fgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}.inv-nueva .fcampo{grid-column:auto}
.inv-res{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin:10px 0}
.inv-res div{border:1px solid var(--borde);border-radius:10px;padding:8px 10px;background:#fff}.inv-res b{display:block;font-size:20px;color:var(--az)}.inv-res small{color:var(--gris)}.inv-res .mal b{color:var(--mal)}
.inv-origen{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.inv-origen .chip.sel{background:var(--cel);border-color:var(--az3);color:var(--az)}
.inv-pasos{margin:6px 0 0 18px;padding:0}.inv-pasos li{margin:6px 0}
.inv-ub0{font-weight:700;color:var(--az)}.inv-ubi{color:var(--gris)}
.inv-dl{display:grid;grid-template-columns:minmax(110px,max-content) 1fr;gap:6px 14px;margin:0}.inv-dl dt{color:var(--gris);font-size:13px}.inv-dl dd{margin:0;overflow-wrap:anywhere}
.inv-hist{list-style:none;margin:0;padding:0}.inv-hist li{border-left:3px solid var(--borde);padding:4px 0 4px 10px;margin:0 0 6px}
@media (max-width:600px){.inv-dl{grid-template-columns:1fr}.inv-dl dd{margin-bottom:6px}.herr .inv-menu,.herr .inv-menu>summary{width:100%}}`;
    document.head.appendChild(st);
    // un menú abierto se cierra al tocar fuera de él
    document.addEventListener('click', (e) => document.querySelectorAll('.inv-menu[open]').forEach((m) => { if (!m.contains(e.target)) m.open = false; }));
  }

  /** Descarga lo que arma el servidor ({nombre, tipo, base64}). */
  async function bajar(ruta) {
    const r = await Nucleo.api('GET', 'm/pim/inventario/' + ruta);
    const bin = Uint8Array.from(atob(r.base64), (c) => c.charCodeAt(0));
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([bin], { type: r.tipo })); a.download = r.nombre; document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 800);
    return r.nombre;
  }
  const MENU_EXP = `<button type="button" data-x="exportar?formato=xlsx">Excel (.xlsx)<small>Ubicaciones y activos. Se edita y se vuelve a importar</small></button>
    <button type="button" data-x="exportar?formato=csv&hoja=activos">CSV de activos</button><button type="button" data-x="exportar?formato=csv&hoja=ubicaciones">CSV de ubicaciones</button>`;
  const MENU_EJ = `<button type="button" data-x="plantilla?formato=xlsx">Planilla de ejemplo (.xlsx)<small>Con instrucciones, filas de ejemplo y las listas de valores</small></button>
    <button type="button" data-x="plantilla?formato=csv&hoja=activos">Ejemplo CSV de activos</button><button type="button" data-x="plantilla?formato=csv&hoja=ubicaciones">Ejemplo CSV de ubicaciones</button>`;
  /** Enlaza los botones de descarga (data-x) de un bloque; el mensaje va a msg. */
  function botonesBajar(el, msg) {
    el.querySelectorAll('[data-x]').forEach((b) => { b.onclick = async () => {
      b.disabled = true; const m = b.closest('.inv-menu'); if (m) m.open = false;
      try { const n = await bajar(b.dataset.x); if (msg) aviso(msg, 'ok', `Descargado: ${n}`); } catch (x) { if (msg) aviso(msg, 'mal', x.message); } finally { b.disabled = false; }
    }; });
    el.querySelectorAll('.inv-menu').forEach((m) => { m.ontoggle = () => { if (!m.open) return; const op = m.querySelector('.op'); op.style.left = '0'; op.style.right = 'auto';
      const r = op.getBoundingClientRect(); if (r.right > window.innerWidth - 8) { op.style.left = 'auto'; op.style.right = '0'; } }; });
  }

  // ---- ubicaciones: árbol con sangría y selector con buscador
  const descendientes = (id) => { const s = new Set([id]); let mas = true; while (mas) { mas = false; INV.ubicaciones.forEach((u) => { if (u.padre && s.has(u.padre) && !s.has(u.id)) { s.add(u.id); mas = true; } }); } return s; };
  const opcionesUbic = (valor, excluir, todas) => INV.ubicaciones.filter((u) => (todas || u.estado === 'active' || u.id === valor) && !(excluir && excluir.has(u.id)))
    // la ruta completa (sin la raíz): elegida, se lee igual que en la ficha del activo; el código sirve para buscar
    .map((u) => `<option value="${h(u.id)}" data-r="${h((u.ruta + ' ' + u.codigo).toLowerCase())}" ${u.id === valor ? 'selected' : ''}>${h(u.ruta)}${u.estado === 'active' ? '' : ' (fuera de uso)'}</option>`).join('');
  /** Un buscador encima de un <select> largo: filtra las opciones por texto (el select nativo sigue andando con teclado y en el celular). */
  function buscable(raiz) {
    raiz.querySelectorAll('.inv-busc').forEach((b) => {
      const q = b.querySelector('input[type=search]'), s = b.querySelector('select'); if (!q || !s) return;
      q.hidden = s.options.length < 14;
      q.oninput = () => { const t = q.value.trim().toLowerCase(); let pri = null;
        [...s.options].forEach((o) => { const ok = !t || !o.value || (o.dataset.r || '').includes(t) || o.text.toLowerCase().includes(t); o.hidden = !ok; if (ok && o.value && !pri) pri = o; });
        if (t && pri && (s.selectedOptions[0]?.hidden || !s.value)) { s.value = pri.value; s.dispatchEvent(new Event('change')); } };
    });
  }
  /** Selector de ubicación con «＋ Nueva ubicación» en el lugar: la crea dentro de la elegida y la deja elegida. */
  const selUbic = (nombre, valor, ed) => `<div class="inv-busc"><input type="search" placeholder="Buscar ubicación por nombre o código…" aria-label="Buscar ubicación">
    <select name="${nombre}"><option value="">— sin ubicación —</option>${opcionesUbic(valor)}</select></div>
    ${ed ? '<button type="button" class="b sec inv-ubn" style="margin-top:6px">＋ Nueva ubicación</button><div class="inv-nueva" hidden></div>' : ''}`;
  function armarSelUbic(raiz) {
    const s = raiz.querySelector('.inv-busc select'), bn = raiz.querySelector('.inv-ubn'), caja = raiz.querySelector('.inv-nueva');
    if (!bn) return;
    bn.onclick = () => {
      if (!caja.hidden) { caja.hidden = true; return; }
      // con una sala elegida, lo habitual es otra sala al lado (misma «dentro de»); con un edificio o un piso, algo dentro de él
      const el0 = INV.ubicaciones.find((u) => u.id === s.value); const hoja = el0 && ['ro', 'area', 'bd'].includes(el0.tipo);
      const padre = hoja ? (el0.padre || '') : s.value; const tp = hoja ? el0.tipo : TIPO_UB_HIJO[el0?.tipo || ''];
      caja.hidden = false;
      caja.innerHTML = `<div class="mini" style="margin-bottom:8px">Queda <b>dentro de</b> la ubicación que elijas: así se arma la ruta Edificio › Piso › Sala.</div>
        <div class="fgrid"><div class="fcampo"><label>Nombre <b class="req">*</b></label><input class="nn" maxlength="160" placeholder="Ej.: Sala de gases"></div>
        <div class="fcampo"><label>Tipo</label><select class="nt">${Object.entries(TIPO_UB).map(([k, t]) => `<option value="${k}" ${k === tp ? 'selected' : ''}>${h(t)}</option>`).join('')}</select></div>
        <div class="fcampo"><label>Dentro de</label><select class="nd"><option value="">${h(raizTxt())} (directo)</option>${opcionesUbic(padre)}</select></div></div>
        <div class="acciones" style="margin-top:8px"><button type="button" class="b nok">Crear y elegir</button><button type="button" class="b sec nca">Cancelar</button></div><div class="nmsg"></div>`;
      const nd = caja.querySelector('.nd'), nt = caja.querySelector('.nt'), nn = caja.querySelector('.nn'); nn.focus();
      nd.onchange = () => { nt.value = TIPO_UB_HIJO[INV.ubicaciones.find((u) => u.id === nd.value)?.tipo || '']; };
      caja.querySelector('.nca').onclick = () => { caja.hidden = true; };
      const crear = async () => { const b = caja.querySelector('.nok'); b.disabled = true;
        try {
          if (nn.value.trim().length < 2) throw new Error('Escribí el nombre de la ubicación');
          const r = await Nucleo.api('POST', 'm/pim/ubicaciones', { nombre: nn.value.trim(), tipo: nt.value, dentro_de: nd.value });
          await traerInv(); s.innerHTML = '<option value="">— sin ubicación —</option>' + opcionesUbic(r.ubicacion.id); s.value = r.ubicacion.id; buscable(raiz);
          caja.hidden = true; s.dispatchEvent(new Event('change'));
        } catch (x) { aviso(caja.querySelector('.nmsg'), 'mal', x.message); } finally { b.disabled = false; } };
      caja.querySelector('.nok').onclick = crear; nn.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); crear(); } };
    };
  }

  // ---- alta y edición de un activo
  const campo = (t, ctl, o = {}) => `<div class="fcampo${o.todo ? ' todo' : ''}" ${o.clase ? `data-clase="${o.clase}"` : ''}><label title="${h(t)}">${h(t)}${o.req ? ' <b class="req">*</b>' : ''}</label>${ctl}${o.ayuda ? `<small class="fayuda">${h(o.ayuda)}</small>` : ''}</div>`;
  const inp = (n, v, o = {}) => `<input name="${n}" type="${o.tipo || 'text'}" value="${h(v ?? '')}" ${o.ph ? `placeholder="${h(o.ph)}"` : ''} ${o.lista ? `list="${o.lista}"` : ''} maxlength="${o.max || 120}" autocomplete="off">`;
  const sel = (n, op, v) => `<select name="${n}">${op.map(([k, t]) => `<option value="${h(k)}" ${String(k) === String(v ?? '') ? 'selected' : ''}>${h(t)}</option>`).join('')}</select>`;
  /** Activos que pueden contener a «a» (todos los en servicio menos él mismo y sus partes). */
  function opcionesPadre(a) {
    let fuera = new Set();
    if (a) { fuera = new Set([a.id]); let mas = true; while (mas) { mas = false; INV.activos.forEach((x) => { if (x.padre_id && fuera.has(x.padre_id) && !fuera.has(x.id)) { fuera.add(x.id); mas = true; } }); } }
    return INV.activos.filter((x) => !fuera.has(x.id) && (x.estado === 'en-servicio' || x.id === a?.padre_id))
      .map((x) => `<option value="${h(x.id)}" ${x.id === a?.padre_id ? 'selected' : ''}>${h(x.codigo)} · ${h(x.nombre)}</option>`).join('');
  }
  async function formActivo(a, ord, alGuardar) {
    const ed = !!a; const v = a || {};
    const fabs = [...new Set(INV.activos.map((x) => x.fabricante).filter(Boolean))];
    const p = panel(ed ? `Editar ${a.codigo}` : 'Nuevo activo', `<form class="form invf" id="faF" novalidate>
      ${ed && a.legado ? '<div class="aviso info">Este activo vino del sistema anterior. Al guardar un cambio pasa a administrarse acá.</div>' : ''}
      <h3 style="margin-top:0">${ed ? 'Qué es' : '1. ¿Qué vas a cargar?'}</h3>
      <div class="inv-clases">${Object.entries(CLASE_TXT).map(([k, t]) => `<label class="${v.clase === k ? 'sel' : ''}"><input type="radio" name="clase" value="${k}" ${v.clase === k ? 'checked' : ''}><b>${h(t)}</b><small>${h(CLASE_AYUDA[k][0])}</small></label>`).join('')}</div>
      <div id="faCuerpo" ${ed ? '' : 'hidden'}>
      <h3>${ed ? 'Datos' : '2. Datos'}</h3>
      <div class="fgrid">
        ${campo('Sistema PIM', sel('sistema', [['', '— elegí el sistema —']].concat(Object.entries(INV.sistemas).map(([c, n]) => [c, `${n} (${c})`])), v.sistema), { req: true, clase: 'sistema', ayuda: 'Anexo B de PIM: define las rutinas y el riesgo de la instalación' })}
        ${campo('Nombre', inp('nombre', v.nombre, { ph: CLASE_AYUDA[v.clase || 'activo'][1], max: 200 }), { req: true, todo: true, ayuda: 'Cómo lo conoce la gente del establecimiento' })}
        ${campo('Tipo', inp('tipo', v.tipo, { ph: CLASE_AYUDA[v.clase || 'activo'][2] }), { ayuda: 'Texto libre: sirve para buscar y agrupar' })}
        ${campo('Código de inventario', inp('codigo', v.codigo, { ph: 'Vacío: lo propone el sistema', max: 60 }), { ayuda: ed ? 'Si lo cambiás, el código nuevo se ve en todas sus órdenes' : 'Si tiene etiqueta, usá ese código' })}
        ${campo('Ubicación', selUbic('ubicacion_id', v.ubicacion_id, puede('pim.inventario.editar')), { todo: true, ayuda: 'Elegí la más precisa (la sala); si no está, creala con «＋ Nueva ubicación»' })}
        ${campo('Forma parte de', `<div class="inv-busc"><input type="search" placeholder="Buscar por código o nombre…" aria-label="Buscar activo"><select name="padre_id"><option value="">— no, es un activo independiente —</option>${opcionesPadre(a)}</select></div>`, { todo: true, ayuda: 'Si es una parte de otro activo: la bomba forma parte del sistema de agua (ISO 14224)' })}
        ${campo('Fabricante o marca', inp('fabricante', v.fabricante, { lista: 'faFab' }))}
        ${campo('Modelo', inp('modelo', v.modelo))}
        ${campo('N.º de serie', inp('serie', v.serie))}
      </div><datalist id="faFab">${fabs.map((x) => `<option value="${h(x)}">`).join('')}</datalist>
      ${seccion('Más datos: NFPA 99, puesta en marcha, ANMAT', `<div class="fgrid">
        ${campo('Categoría NFPA 99', sel('categoria_nfpa99', NFPA, v.categoria_nfpa99), { ayuda: 'Qué pasa con el paciente si falla' })}
        ${campo('Puesta en marcha', inp('puesta_en_marcha', v.puesta_en_marcha, { tipo: 'date' }))}
        ${campo('N.º ANMAT', inp('anmat_pm', v.anmat_pm, { ph: 'PM-2430-65', max: 40 }), { clase: 'equipo-medico' })}
        ${campo('Clase de riesgo ANMAT', sel('clase_riesgo_pm', [['', '— sin dato —'], ['I', 'I'], ['II', 'II'], ['III', 'III'], ['IV', 'IV']], v.clase_riesgo_pm), { clase: 'equipo-medico' })}</div>`, !!(v.categoria_nfpa99 || v.puesta_en_marcha || v.anmat_pm))}
      <div id="faAvisoCod"></div>
      <div class="acciones"><button class="b" type="submit">${ed ? 'Guardar los cambios' : 'Dar de alta'}</button><button class="b sec" type="button" id="faCan">Cancelar</button></div><div class="fmsg"></div></div></form>`);
    const f = p.querySelector('#faF'), cuerpo = f.querySelector('#faCuerpo');
    const mostrar = () => {
      const cl = f.elements.clase.value; cuerpo.hidden = !cl;
      f.querySelectorAll('.inv-clases label').forEach((l) => l.classList.toggle('sel', l.querySelector('input').checked));
      f.querySelectorAll('[data-clase]').forEach((c) => { c.hidden = c.dataset.clase !== cl; });
      if (cl) { f.elements.nombre.placeholder = CLASE_AYUDA[cl][1]; f.elements.tipo.placeholder = CLASE_AYUDA[cl][2]; }
    };
    f.querySelectorAll('input[name=clase]').forEach((r) => { r.onchange = () => { mostrar(); if (!ed) f.elements.nombre.focus(); }; });
    mostrar(); buscable(f); armarSelUbic(f.querySelector('[name=ubicacion_id]').closest('.fcampo'));
    p.querySelector('#faCan').onclick = () => (ed ? verActivo(a.id) : Nucleo.cerrar());
    // fabricantes de la lista del catálogo, además de los que ya están en el inventario
    Nucleo.listas(['fabricante']).then((L) => { const dl = f.querySelector('#faFab'); (L.fabricante?.valores || []).forEach((x) => { if (!fabs.includes(x.texto)) dl.insertAdjacentHTML('beforeend', `<option value="${h(x.texto)}">`); }); }).catch(() => {});
    if (ed && ord?.total) f.elements.codigo.oninput = () => {
      const c = f.elements.codigo.value.trim();
      p.querySelector('#faAvisoCod').innerHTML = c && c !== a.codigo ? `<div class="aviso info">Tiene ${plural(ord.total, 'orden', 'órdenes')}: todas van a mostrar el código ${h(c)}, y los informes, las estadísticas y el plan preventivo se ponen al día solos.</div>` : '';
    };
    f.onsubmit = async (e) => {
      e.preventDefault(); const msg = f.querySelector('.fmsg'), b = f.querySelector('button[type=submit]');
      const E = f.elements; const cl = E.clase.value;
      const d = { clase: cl, sistema: cl === 'sistema' ? E.sistema.value : '', nombre: E.nombre.value.trim(), tipo: E.tipo.value.trim(), codigo: E.codigo.value.trim(),
        ubicacion_id: E.ubicacion_id.value, padre_id: E.padre_id.value, fabricante: E.fabricante.value.trim(), modelo: E.modelo.value.trim(), serie: E.serie.value.trim(),
        categoria_nfpa99: E.categoria_nfpa99.value, puesta_en_marcha: E.puesta_en_marcha.value,
        anmat_pm: cl === 'equipo-medico' ? E.anmat_pm.value.trim() : '', clase_riesgo_pm: cl === 'equipo-medico' ? E.clase_riesgo_pm.value : '' };
      try {
        if (!cl) throw new Error('Elegí qué es: instalación, equipo médico u otro activo');
        if (cl === 'sistema' && !d.sistema) throw new Error('Elegí el sistema PIM de la instalación');
        if (d.nombre.length < 2) throw new Error('Escribí el nombre');
        if (ed) {
          if (!d.codigo) d.codigo = a.codigo;
          // en la edición, un dato que se vació se borra («-»): una celda vacía de la planilla, en cambio, no toca nada
          ['tipo', 'fabricante', 'modelo', 'serie', 'categoria_nfpa99', 'puesta_en_marcha', 'anmat_pm', 'clase_riesgo_pm'].forEach((k) => { if (d[k] === '' && (a[k] || '') !== '') d[k] = '-'; });
          d.version = a.version;
        } else if (!d.codigo) delete d.codigo;
        b.disabled = true;
        const r = ed ? await Nucleo.api('PUT', 'm/pim/activos/' + a.id, d) : await Nucleo.api('POST', 'm/pim/activos', d);
        await traerInv(); if (alGuardar) alGuardar();
        const cambioCod = ed && r.activo.codigo !== a.codigo;
        verActivo(r.activo.id, ed ? `Cambios guardados.${cambioCod && r.ordenes.total ? ` Sus ${plural(r.ordenes.total, 'orden muestra', 'órdenes muestran')} ya el código ${r.activo.codigo}; informes y plan preventivo se actualizan en menos de un minuto.` : ''}`
          : `Activo ${r.activo.codigo} dado de alta. Ya se le pueden pedir trabajos.`);
      } catch (x) { aviso(msg, 'mal', x.message); } finally { b.disabled = false; }
    };
  }

  // ---- ficha del activo: datos, órdenes, historial; editar, sacar de circulación y volver a poner en servicio
  async function verActivo(id, okMsg) {
    const a = INV.activos.find((x) => x.id === id); if (!a) return;
    const ed = puede('pim.inventario.editar');
    const padre = a.padre_id ? INV.activos.find((x) => x.id === a.padre_id) : null;
    const partes = INV.activos.filter((x) => x.padre_id === a.id);
    const fila = (t, v) => (v ? `<dt>${h(t)}</dt><dd>${v}</dd>` : '');
    const p = panel(`${a.codigo} · ${a.nombre}`, `<div id="avMsg"></div>
      <dl class="inv-dl">${fila('Estado', etInv(a.estado) + (a.motivo_estado ? ` <span class="mini">${h(a.motivo_estado)}</span>` : ''))}
        ${fila('Qué es', h(CLASE_TXT[a.clase]) + (a.sistema ? ` · ${h(INV.sistemas[a.sistema] || a.sistema)} (${h(a.sistema)})` : ''))}${fila('Tipo', h(a.tipo))}
        ${fila('Ubicación', a.ubicacion ? `${h(raizTxt())} › <b>${h(a.ubicacion)}</b>` : '<span class="mini">sin ubicación</span>')}
        ${fila('Forma parte de', padre ? `${h(padre.codigo)} · ${h(padre.nombre)}` : '')}${fila('Sus partes', partes.map((x) => `${h(x.codigo)} · ${h(x.nombre)}`).join('<br>'))}
        ${fila('Marca y modelo', h([a.fabricante, a.modelo].filter(Boolean).join(' · ')))}${fila('N.º de serie', h(a.serie))}
        ${fila('NFPA 99', a.categoria_nfpa99 ? h((NFPA.find((x) => x[0] === a.categoria_nfpa99) || [0, a.categoria_nfpa99])[1]) : '')}${fila('Puesta en marcha', h(a.puesta_en_marcha))}
        ${fila('ANMAT', h([a.anmat_pm, a.clase_riesgo_pm ? 'clase ' + a.clase_riesgo_pm : ''].filter(Boolean).join(' · ')))}</dl>
      <div class="acciones" style="margin-top:12px">${ed ? `<button class="b" id="avEd">Editar</button>${a.estado === 'en-servicio' ? '<button class="b sec" id="avArch">Sacar de circulación</button>' : '<button class="b sec" id="avRea">Volver a poner en servicio</button>'}` : ''}
        ${puede('pim.solicitar') && a.estado === 'en-servicio' ? '<button class="b sec" id="avSol">Pedir un trabajo</button>' : ''}</div>
      <div id="avZona"></div><h3>Órdenes</h3><div id="avOrd" class="mini">Cargando…</div><h3>Historial</h3><div id="avHist" class="mini">Cargando…</div>`);
    if (okMsg) aviso(p.querySelector('#avMsg'), 'ok', okMsg);
    let F = null;
    const cargarFicha = async () => { F = await Nucleo.api('GET', 'm/pim/activos/' + id); return F; };
    p.querySelector('#avSol')?.addEventListener('click', () => { PRESEL = a.id; Nucleo.cerrar(); Nucleo.ir('solicitud'); });
    p.querySelector('#avEd')?.addEventListener('click', async () => { if (!F) await cargarFicha().catch(() => null); formActivo(a, F?.ordenes, repintarInv); });
    p.querySelector('#avRea')?.addEventListener('click', async (e) => {
      e.target.disabled = true;
      try { await Nucleo.api('POST', `m/pim/activos/${a.id}/estado`, { estado: 'en-servicio', version: a.version }); await traerInv(); repintarInv();
        verActivo(a.id, 'Volvió a estar en servicio: aparece otra vez para pedir trabajos, y su plan preventivo (si tenía) se reanuda.'); }
      catch (x) { aviso(p.querySelector('#avMsg'), 'mal', x.message); e.target.disabled = false; }
    });
    p.querySelector('#avArch')?.addEventListener('click', () => {
      const z = p.querySelector('#avZona'); if (z.innerHTML) { z.innerHTML = ''; return; }
      const ab = F?.ordenes?.abiertas || 0;
      z.innerHTML = `<div class="tarjeta" style="margin:12px 0 0"><h3 style="margin-top:0">Sacar de circulación</h3>
        <p class="mini">No se borra: queda en el inventario, con su historial y sus órdenes. Deja de aparecer para pedir trabajos y su plan preventivo se pausa. Se puede volver a poner en servicio.</p>
        ${ab ? `<div class="aviso info">Tiene ${plural(ab, 'orden abierta', 'órdenes abiertas')}: siguen su curso normalmente.</div>` : ''}
        <form class="form invf" id="arF"><div class="inv-clases">${[['fuera-de-circulacion', 'Fuera de circulación', 'Guardado, prestado o en reparación externa: puede volver'], ['obsoleto', 'Obsoleto o dado de baja', 'Se dio de baja para siempre'], ['cargado-por-error', 'Cargado por error', 'Duplicado o mal cargado']]
          .map(([k, t, s], i) => `<label class="${i ? '' : 'sel'}"><input type="radio" name="estado" value="${k}" ${i ? '' : 'checked'}><b>${h(t)}</b><small>${h(s)}</small></label>`).join('')}</div>
        <div class="fgrid" style="margin-top:10px">${campo('Motivo', '<textarea name="motivo" maxlength="280" placeholder="Ej.: reemplazado por ACT-014; enviado a reparar al fabricante; duplicado de GAS-O2-001"></textarea>', { req: true, todo: true })}</div>
        <div class="acciones"><button class="b" type="submit">Confirmar</button><button class="b sec" type="button" id="arCan">Cancelar</button></div><div class="fmsg"></div></form></div>`;
      const f = z.querySelector('#arF');
      f.querySelectorAll('input[name=estado]').forEach((r) => { r.onchange = () => f.querySelectorAll('.inv-clases label').forEach((l) => l.classList.toggle('sel', l.querySelector('input').checked)); });
      z.querySelector('#arCan').onclick = () => { z.innerHTML = ''; };
      f.onsubmit = async (e) => { e.preventDefault(); const b = f.querySelector('button[type=submit]'); b.disabled = true;
        try { const est = f.elements.estado.value;
          await Nucleo.api('POST', `m/pim/activos/${a.id}/estado`, { estado: est, motivo: f.elements.motivo.value.trim(), version: a.version }); await traerInv(); repintarInv();
          verActivo(a.id, `Quedó «${EST_INV[est][1]}». Ya no aparece para pedir trabajos; lo encontrás con el filtro «Fuera de servicio» del inventario.`);
        } catch (x) { aviso(f.querySelector('.fmsg'), 'mal', x.message); } finally { b.disabled = false; } };
      f.elements.motivo.focus();
    });
    try {
      await cargarFicha();
      const o = F.ordenes;
      p.querySelector('#avOrd').innerHTML = (o.total ? `<p>${plural(o.total, 'orden', 'órdenes')}${o.abiertas ? `, ${o.abiertas} sin cerrar` : ''}. Las últimas:</p>` : '<p>Todavía no tiene órdenes.</p>')
        + (F.ultimas.length ? tabla([{ t: 'Número', v: (x) => x.numero }, { t: 'Estado', html: true, v: (x) => etEstado(x.estado) }, { t: 'Alta', v: (x) => fecha(x.alta) }, { t: 'Qué', v: (x) => x.titulo }], F.ultimas) : '');
      p.querySelector('#avHist').innerHTML = `<ul class="inv-hist">${F.versiones.map((x) => `<li><b>${fecha(x.fecha)}</b> · ${h(x.accion)}${x.cambios.length ? ': ' + h(x.cambios.join(', ')) : ''}
        ${x.estado !== 'en-servicio' && x.accion === 'Cambio' ? ' ' + etInv(x.estado) : ''}${x.motivo ? `<br><span class="mini">${h(x.motivo)}</span>` : ''}</li>`).join('')}</ul>`;
    } catch (x) { p.querySelector('#avOrd').textContent = 'No se pudo leer: ' + x.message; p.querySelector('#avHist').textContent = ''; }
  }

  // ---- ubicación: alta y edición
  function verUbic(u) {
    const ed = puede('pim.inventario.editar'); if (!ed) return;
    const excl = u ? descendientes(u.id) : null;
    const p = panel(u ? `Ubicación ${u.nombre}` : 'Nueva ubicación', `<p class="mini">${u ? `${h(raizTxt())} › ${h(u.ruta)} · ${plural(u.activos, 'activo en servicio', 'activos en servicio')}` : 'Cada ubicación va <b>dentro de</b> otra: edificio › piso › sala. Las de primer nivel (los edificios) dependen directamente del establecimiento.'}</p>
      <form class="form invf" id="ubF"><div class="fgrid">
        ${campo('Nombre', inp('nombre', u?.nombre, { ph: 'Ej.: Sala de gases', max: 160 }), { req: true })}
        ${campo('Tipo', sel('tipo', Object.entries(TIPO_UB), u?.tipo || 'bu'))}
        ${campo('Dentro de', `<div class="inv-busc"><input type="search" placeholder="Buscar…" aria-label="Buscar ubicación"><select name="dentro_de"><option value="">${h(raizTxt())} (directo)</option>${opcionesUbic(u?.padre, excl)}</select></div>`, { todo: true })}
        ${campo('Código', inp('codigo', u?.codigo, { ph: 'Vacío: lo propone el sistema', max: 40 }))}
        ${u ? campo('Estado', sel('estado', [['active', 'En uso'], ['inactive', 'Fuera de uso']], u.estado), { ayuda: 'Fuera de uso: no se ofrece para activos nuevos' }) : ''}
      </div><div class="acciones"><button class="b" type="submit">${u ? 'Guardar' : 'Crear ubicación'}</button></div><div class="fmsg"></div></form>`);
    const f = p.querySelector('#ubF'); buscable(f);
    if (!u) f.elements.dentro_de.onchange = () => { f.elements.tipo.value = TIPO_UB_HIJO[INV.ubicaciones.find((x) => x.id === f.elements.dentro_de.value)?.tipo || '']; };
    f.onsubmit = async (e) => { e.preventDefault(); const b = f.querySelector('button[type=submit]'); b.disabled = true;
      const d = { nombre: f.elements.nombre.value.trim(), tipo: f.elements.tipo.value, dentro_de: f.elements.dentro_de.value, codigo: f.elements.codigo.value.trim() };
      if (u) d.estado = f.elements.estado.value;
      try {
        const r = u ? await Nucleo.api('PUT', 'm/pim/ubicaciones/' + u.id, d) : await Nucleo.api('POST', 'm/pim/ubicaciones', d);
        await traerInv(); repintarInv();
        aviso(f.querySelector('.fmsg'), 'ok', u ? 'Guardado.' + (d.estado === 'inactive' && u.activos ? ` Sus ${u.activos} activos siguen ahí; la ubicación ya no se ofrece para activos nuevos.` : '') : `Creada: ${raizTxt()} › ${r.ubicacion.ruta} (${r.ubicacion.codigo}).`);
        if (!u) { f.reset(); f.elements.nombre.focus(); f.elements.dentro_de.innerHTML = `<option value="">${h(raizTxt())} (directo)</option>${opcionesUbic(r.ubicacion.id)}`; f.elements.dentro_de.value = r.ubicacion.padre || '';
          f.elements.tipo.value = r.ubicacion.tipo; buscable(f); }
      } catch (x) { aviso(f.querySelector('.fmsg'), 'mal', x.message); } finally { b.disabled = false; } };
    f.elements.nombre.focus();
  }

  // ---- importar: archivo, enlace de Google Sheets o texto pegado; primero se revisa, después se guarda
  const leerArchivo = (a) => new Promise((ok, mal) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1] || ''); r.onerror = () => mal(new Error('No se pudo leer el archivo')); r.readAsDataURL(a); });
  function importarInv() {
    const p = panel('Importar una planilla', `<p class="mini">Para cargar de una vez lo que ya tenés relevado, o corregir en cantidad: una fila con un código que ya existe <b>actualiza</b> ese activo; con un código nuevo, lo <b>da de alta</b>. Una celda vacía no cambia nada; un guion (-) borra el dato. <b>Primero se revisa</b>: nada se guarda hasta que confirmes.</p>
      <div class="acciones" style="margin:0 0 6px"><details class="inv-menu"><summary>Bajar la planilla de ejemplo ▾</summary><div class="op">${MENU_EJ}</div></details></div><div id="impBaj"></div>
      <h3>1. ¿De dónde la traigo?</h3>
      <div class="inv-origen"><button type="button" class="chip sel" data-o="archivo">Archivo .xlsx o .csv</button><button type="button" class="chip" data-o="enlace">Hoja de Google Sheets</button><button type="button" class="chip" data-o="texto">Copiar y pegar</button></div>
      <div data-p="archivo"><input type="file" id="impArch" accept=".xlsx,.csv,.txt"><small class="fayuda">Excel con las hojas «Ubicaciones» y «Activos» (las dos o una), o un .csv de una hoja. Hasta 1,5 MB.</small></div>
      <div data-p="enlace" hidden><input id="impEnl" type="url" placeholder="https://docs.google.com/spreadsheets/d/…"><small class="fayuda">En Google Sheets: Compartir › Acceso general › «Cualquier persona con el enlace» (Lector), y pegá el enlace. Se leen todas las hojas.</small></div>
      <div data-p="texto" hidden><textarea id="impTxt" rows="7" placeholder="En Excel o Sheets seleccioná las celdas con la fila de títulos, copiá (Ctrl+C) y pegá acá (Ctrl+V)."></textarea></div>
      <h3>2. Opciones</h3><label class="fchk"><input type="checkbox" id="impCrear" checked> Crear las ubicaciones que falten, según la columna «Ubicación» (Edificio > Piso > Sala)</label>
      <div class="acciones"><button class="b" type="button" id="impRev">Revisar la planilla</button></div><div id="impRes"></div>`);
    let origen = 'archivo', revisado = null;
    botonesBajar(p, p.querySelector('#impBaj'));
    const res = p.querySelector('#impRes');
    const invalidar = () => { if (revisado) { revisado = null; res.innerHTML = '<p class="mini">Cambiaste la planilla o las opciones: tocá «Revisar la planilla» otra vez.</p>'; } };
    p.querySelectorAll('[data-o]').forEach((b) => { b.onclick = () => { origen = b.dataset.o; p.querySelectorAll('[data-o]').forEach((x) => x.classList.toggle('sel', x === b)); p.querySelectorAll('[data-p]').forEach((x) => { x.hidden = x.dataset.p !== origen; }); invalidar(); }; });
    ['#impArch', '#impEnl', '#impTxt', '#impCrear'].forEach((s) => p.querySelector(s).addEventListener('change', invalidar));
    p.querySelector('#impTxt').addEventListener('input', invalidar);
    const armar = async () => {
      const c = { crear_ubicaciones: p.querySelector('#impCrear').checked };
      if (origen === 'archivo') { const a = p.querySelector('#impArch').files[0]; if (!a) throw new Error('Elegí el archivo');
        if (a.size > MAX_ARCHIVO) throw new Error(`El archivo pesa ${(a.size / 1048576).toFixed(1)} MB y el máximo es 1,5 MB. Guardalo como .xlsx (comprime) o partilo en dos.`);
        c.base64 = await leerArchivo(a); c.nombre = a.name; }
      else if (origen === 'enlace') { c.enlace = p.querySelector('#impEnl').value.trim(); if (!/^https:\/\//.test(c.enlace)) throw new Error('Pegá el enlace completo de la hoja (empieza con https://)'); }
      else { c.texto = p.querySelector('#impTxt').value; if (c.texto.trim().split(/\n/).length < 2) throw new Error('Pegá la fila de títulos y al menos una fila de datos'); }
      return c;
    };
    const tablaErr = (l, hoja) => l.map((x) => ({ ...x, hoja }));
    const pintar = (r, aplicado) => {
      const U = r.ubicaciones, A = r.activos; const errores = tablaErr(U.errores, 'Ubicaciones').concat(tablaErr(A.errores, 'Activos'));
      const nUb = U.nuevas + r.ubicaciones_a_crear.length, total = A.nuevos + A.cambian + nUb + U.cambian;
      const caja = (n, t, mal) => `<div class="${mal && n ? 'mal' : ''}"><b>${n}</b><small>${h(t)}</small></div>`;
      const hojas = [r.hojas.ubicaciones ? `«${r.hojas.ubicaciones}» como ubicaciones` : '', r.hojas.activos ? `«${r.hojas.activos}» como activos` : ''].filter(Boolean).join(' y ');
      res.innerHTML = `<h3>${aplicado ? 'Resultado' : '3. Qué va a pasar'}</h3><p class="mini">Leí ${h(hojas || 'la planilla')} (${h(r.origen)}).</p>
        <div class="inv-res">${caja(A.nuevos, aplicado ? 'activos nuevos' : 'activos nuevos')}${caja(A.cambian, 'activos que cambian')}${caja(A.iguales, 'activos sin cambios')}${caja(nUb, 'ubicaciones nuevas')}${caja(U.cambian, 'ubicaciones que cambian')}${caja(errores.length, 'filas con error', 1)}</div>
        ${aplicado ? `<div class="aviso ${errores.length ? 'info' : 'ok'}">Listo: se guardaron ${plural(r.guardados, 'activo', 'activos')}${nUb + U.cambian ? ` y ${plural(nUb + U.cambian, 'ubicación', 'ubicaciones')}` : ''}.${errores.length ? ' Las filas con error no se guardaron: corregilas en la planilla y volvé a importarla (lo que ya entró queda igual).' : ''}</div>` : ''}
        ${r.columnas_ignoradas.length ? `<div class="aviso info">Estas columnas no tienen lugar en el inventario y no se usan: ${h(r.columnas_ignoradas.join(', '))}.</div>` : ''}
        ${r.ubicaciones_a_crear.length ? seccion(`Ubicaciones que se ${aplicado ? 'crearon' : 'van a crear'} (${r.ubicaciones_a_crear.length})`, `<ul>${r.ubicaciones_a_crear.slice(0, 200).map((x) => `<li>${h(x)}</li>`).join('')}</ul>`) : ''}
        ${errores.length ? `<h3>Filas con error</h3>${tabla([{ t: 'Hoja', v: (x) => x.hoja }, { t: 'Fila', v: (x) => x.fila }, { t: 'Código', v: (x) => x.codigo }, { t: 'Qué pasa', v: (x) => x.motivo }], errores.slice(0, 300))}` : ''}
        ${!aplicado && A.detalle.length ? seccion(`Detalle de los activos (${A.nuevos + A.cambian})`, tabla([{ t: 'Fila', v: (x) => x.fila }, { t: 'Código', v: (x) => x.codigo }, { t: 'Nombre', v: (x) => x.nombre },
          { t: 'Qué pasa', html: true, v: (x) => (x.accion === 'nuevo' ? '<span class="et verde">Nuevo</span>' : `<span class="et ama">Cambia</span> ${h(x.que.join(', '))}`) }], A.detalle), A.detalle.length <= 30) : ''}
        ${!aplicado ? `<div class="acciones">${total ? `<button class="b" type="button" id="impGuardar">Guardar ${plural(total, 'cambio', 'cambios')}</button>` : '<p class="mini">No hay nada nuevo para guardar.</p>'}</div>` : '<div class="acciones"><button class="b sec" type="button" id="impCerrar">Cerrar y ver el inventario</button></div>'}<div id="impMsg"></div>`;
      res.querySelector('#impCerrar')?.addEventListener('click', () => Nucleo.cerrar());
      const bg = res.querySelector('#impGuardar');
      if (bg) bg.onclick = async () => { bg.disabled = true; aviso(res.querySelector('#impMsg'), 'info', 'Guardando… con muchas filas puede tardar un minuto.');
        try { const r2 = await Nucleo.api('POST', 'm/pim/inventario/importar', { ...revisado, simular: false }); revisado = null; await traerInv(); repintarInv(); pintar(r2, true); }
        catch (x) { aviso(res.querySelector('#impMsg'), 'mal', x.message); bg.disabled = false; } };
    };
    p.querySelector('#impRev').onclick = async (e) => { const b = e.target; b.disabled = true; aviso(res, 'info', 'Leyendo la planilla…');
      try { const c = await armar(); const r = await Nucleo.api('POST', 'm/pim/inventario/importar', { ...c, simular: true }); revisado = c; pintar(r, false); }
      catch (x) { revisado = null; aviso(res, 'mal', x.message); } finally { b.disabled = false; } };
  }

  // ---- la pestaña
  let repintarInv = () => {};
  Nucleo.pestana({ id: 'inventario', titulo: 'Inventario', orden: 25, cap: 'pim.usar', render: async (el) => {
    estilosInv(); await traerInv();
    const ed = puede('pim.inventario.editar');
    el.innerHTML = `<div class="tarjeta" id="invEst"></div><div id="invKpis" style="margin-top:12px"></div>
      <div class="tarjeta" id="invActivos" style="margin-top:12px"><div class="tcab"><h2>Activos</h2></div>
        <div class="herr">${ed ? '<button class="b" type="button" id="invNuevo">＋ Nuevo activo</button><button class="b sec" type="button" id="invImp">Importar planilla</button>' : ''}
          <details class="inv-menu"><summary>Exportar ▾</summary><div class="op">${MENU_EXP}</div></details>
          <details class="inv-menu"><summary>Planilla de ejemplo ▾</summary><div class="op">${MENU_EJ}</div></details></div><div id="invBaj"></div>
        <div class="filtros"><div style="flex:2 1 220px"><label>Buscar</label><input id="iQ" type="search" placeholder="Código, nombre, tipo, marca, modelo o serie"></div>
          <div style="flex:2 1 220px"><label>Ubicación</label><select id="iLoc"></select></div>
          <div style="flex:1 1 150px"><label>Qué es</label><select id="iCl"><option value="">Todo</option>${Object.entries(CLASE_TXT).map(([k, t]) => `<option value="${k}">${h(t)}</option>`).join('')}</select></div>
          <div style="flex:1 1 160px"><label>Estado</label><select id="iEst"><option value="en-servicio">En servicio</option><option value="fuera">Fuera de servicio (archivados)</option><option value="">Todos</option></select></div></div>
        <div id="iCuenta" class="mini" style="margin-bottom:6px"></div><div id="iLista"></div></div>
      <div class="tarjeta" id="ubTarjeta"><div class="tcab"><h2>Ubicaciones</h2>${ed ? '<button class="b sec" type="button" id="ubNueva">＋ Nueva ubicación</button>' : ''}</div>
        <p class="mini">Forman un árbol: <b>edificio › piso › sala</b>, cada una «dentro de» otra. Al cargar un activo se elige la más precisa y la ruta completa se arma sola. ${ed ? 'Tocá una fila para cambiarla.' : ''}</p><div id="ubLista"></div></div>`;
    botonesBajar(el, el.querySelector('#invBaj'));
    const $ = (s) => el.querySelector(s);
    const pintarEstab = () => {
      const E = INV.establecimiento;
      $('#invEst').innerHTML = `<div class="inv-estab"><div style="flex:1;min-width:200px"><small>Establecimiento</small><div class="nom">${h(raizTxt())}</div><small>Es la raíz de todas las ubicaciones${E.sigla ? ` · sigla ${h(E.sigla)}` : ''}</small></div>
        ${ed ? '<button class="b sec" type="button" id="estEd">Cambiar el nombre</button>' : ''}</div>
        ${!E.nombre ? `<div class="aviso info" style="margin-bottom:0">Todavía no se escribió el nombre del establecimiento: se muestra el del nodo.${ed ? ' Escribí el nombre completo de la institución con «Cambiar el nombre».' : ''}</div>` : ''}<div id="estZona"></div>`;
      $('#estEd')?.addEventListener('click', () => {
        const z = $('#estZona'); if (z.innerHTML) { z.innerHTML = ''; return; }
        z.innerHTML = `<form class="form invf" style="margin-top:10px"><div class="fgrid">${campo('Nombre del establecimiento', inp('nombre', E.nombre || E.sugerido, { max: 120 }), { req: true, todo: true, ayuda: 'Encabeza las rutas de ubicación, los informes y las planillas' })}</div>
          <div class="acciones"><button class="b" type="submit">Guardar</button><button class="b sec" type="button">Cancelar</button></div><div class="fmsg"></div></form>`;
        const f = z.querySelector('form'); f.elements.nombre.focus(); f.elements.nombre.select();
        f.querySelector('button[type=button]').onclick = () => { z.innerHTML = ''; };
        f.onsubmit = async (e) => { e.preventDefault(); try { const r = await Nucleo.api('PUT', 'm/pim/establecimiento', { nombre: f.elements.nombre.value.trim() }); INV.establecimiento = r.establecimiento; pintarEstab(); pintarUbic(); }
          catch (x) { aviso(f.querySelector('.fmsg'), 'mal', x.message); } };
      });
    };
    const pintarKpis = () => {
      const A = INV.activos, en = A.filter((a) => a.estado === 'en-servicio');
      $('#invKpis').innerHTML = kpis([{ k: 'en-servicio', t: 'En servicio', n: en.length, sub: `de ${A.length} cargados`, icono: 'inventario', color: '#1e88e5' },
        { k: 'fuera', t: 'Fuera de servicio', n: A.length - en.length, sub: 'archivados, sin borrar', icono: 'reloj', color: '#78909c' },
        { k: 'sin', t: 'Sin ubicación', n: en.filter((a) => !a.ubicacion_id).length, sub: 'conviene asignarla', icono: 'alarmas', color: '#ef6c00' },
        { k: 'ubic', t: 'Ubicaciones', n: INV.ubicaciones.filter((u) => u.estado === 'active').length, sub: 'edificios, pisos y salas', icono: 'inicio', color: '#00897b' }]);
      Nucleo.alClicKpi($('#invKpis'), (k) => {
        if (k === 'ubic') { $('#ubTarjeta').scrollIntoView({ behavior: 'smooth' }); return; }
        $('#iQ').value = ''; $('#iCl').value = ''; $('#iEst').value = k === 'fuera' ? 'fuera' : 'en-servicio'; $('#iLoc').value = k === 'sin' ? '-' : ''; pintarLista();
      });
    };
    const pintarFiltroUbic = () => { const s = $('#iLoc'), v = s.value; s.innerHTML = `<option value="">Todas</option><option value="-">Sin ubicación</option>${opcionesUbic(null, null, true)}`; s.value = [...s.options].some((o) => o.value === v) ? v : ''; };
    const pintarLista = () => {
      const q = $('#iQ').value.trim().toLowerCase(), lo = $('#iLoc').value, cl = $('#iCl').value, es = $('#iEst').value;
      const dentro = lo && lo !== '-' ? descendientes(lo) : null;
      const l = INV.activos.filter((a) => (!cl || a.clase === cl) && (!es || (es === 'fuera' ? a.estado !== 'en-servicio' : a.estado === es))
        && (!lo || (lo === '-' ? !a.ubicacion_id : dentro.has(a.ubicacion_id)))
        && (!q || [a.codigo, a.nombre, a.tipo, a.fabricante, a.modelo, a.serie, a.ubicacion, a.sistema, INV.sistemas[a.sistema]].join(' ').toLowerCase().includes(q)));
      const MAX = 300;
      $('#iCuenta').textContent = l.length > MAX ? `Se muestran ${MAX} de ${l.length}: afiná la búsqueda o los filtros.` : INV.activos.length ? plural(l.length, 'activo', 'activos') : '';
      $('#iLista').innerHTML = l.length ? tabla([{ t: 'Código', v: (a) => a.codigo }, { t: 'Nombre', v: (a) => a.nombre },
        { t: 'Qué es', v: (a) => CLASE_TXT[a.clase] + (a.sistema ? ' · ' + a.sistema : '') + (a.tipo ? ' · ' + a.tipo : '') },
        { t: 'Ubicación', v: (a) => a.ubicacion }, { t: 'Marca y modelo', v: (a) => [a.fabricante, a.modelo].filter(Boolean).join(' · ') },
        { t: 'Estado', html: true, v: (a) => etInv(a.estado) }], l.slice(0, MAX), (a) => verActivo(a.id))
        : INV.activos.length ? '<p class="mini">Ningún activo coincide con los filtros.</p>'
          : `<div class="aviso info"><b>El inventario está vacío.</b> Tres formas de empezar:<ol class="inv-pasos">
            <li><b>Ubicaciones:</b> cargá el edificio, el piso y la sala más abajo (o dejá que se creen solas al importar).</li>
            <li><b>De a uno:</b> «＋ Nuevo activo» te pregunta primero qué es (instalación, equipo médico u otro) y después los datos.</li>
            <li><b>Lo que ya tenés relevado:</b> bajá la «Planilla de ejemplo», completala (o adaptá la tuya con esos títulos) y usá «Importar planilla». También se puede pegar el enlace de una hoja de Google Sheets.</li></ol></div>`;
    };
    const pintarUbic = () => {
      const L = INV.ubicaciones;
      $('#ubLista').innerHTML = L.length ? tabla([{ t: 'Ubicación', html: true, v: (u) => `<span style="padding-left:${u.nivel * 18}px" class="${u.nivel ? 'inv-ubi' : 'inv-ub0'}">${u.nivel ? '└ ' : ''}</span><span class="${u.nivel ? '' : 'inv-ub0'}">${h(u.nombre)}</span>` },
        { t: 'Tipo', v: (u) => TIPO_UB[u.tipo] || u.tipo }, { t: 'Código', v: (u) => u.codigo }, { t: 'Activos', v: (u) => u.activos || '' },
        { t: 'Estado', html: true, v: (u) => (u.estado === 'active' ? '' : '<span class="et gris">Fuera de uso</span>') }], L, ed ? verUbic : null)
        : `<p class="mini">Todavía no hay ubicaciones. Empezá por el edificio: «＋ Nueva ubicación», tipo «Edificio». Después, dentro de él, los pisos, y dentro de cada piso, las salas.</p>`;
    };
    repintarInv = () => { if (!document.body.contains($('#iLista'))) return; pintarEstab(); pintarKpis(); pintarFiltroUbic(); pintarLista(); pintarUbic(); };
    let t; $('#iQ').oninput = () => { clearTimeout(t); t = setTimeout(pintarLista, 150); };
    ['#iLoc', '#iCl', '#iEst'].forEach((s) => { $(s).onchange = pintarLista; });
    $('#invNuevo')?.addEventListener('click', () => formActivo(null, null, repintarInv));
    $('#invImp')?.addEventListener('click', importarInv);
    $('#ubNueva')?.addEventListener('click', () => verUbic(null));
    repintarInv();
  } });
})();
