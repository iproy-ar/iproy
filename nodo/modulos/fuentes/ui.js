// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo fuentes · 1.0.0 (Ola 4): Configuración › Fuentes de datos. Lista con su estado, alta y edición (reglas en JSON),
// probar sin publicar, activar, leer ahora, traer historial y paquete (exportar / importar).
(() => {
  const { h, fecha, aviso, api, puede, kpis, alClicKpi, icono, tabla } = Nucleo;
  const EST = { ok: ['verde', 'leyendo bien'], aviso: ['ama', 'lee con avisos'], error: ['rojo', 'con error'] };
  const hace = (ts) => { if (!ts) return 'nunca'; const s = (Date.now() - new Date(ts.replace(' ', 'T'))) / 1000; return s < 90 ? 'recién' : s < 3600 ? `hace ${Math.round(s / 60)} min` : s < 86400 ? `hace ${Math.round(s / 3600)} h` : `hace ${Math.round(s / 86400)} d`; };
  const J = (v) => v == null ? '' : JSON.stringify(v, null, 1);
  let filtro = '';

  Nucleo.configuracion({ id: 'fuentes', titulo: 'Fuentes de datos', render: async (el) => {
    if (!puede('fuentes.ver')) { el.innerHTML = '<p class="mini">Sin permiso para ver las fuentes de datos.</p>'; return; }
    const cfg = puede('fuentes.configurar'); const r = await api('GET', 'm/fuentes/fuentes'); const F = r.fuentes;
    const est = (f) => !f.activa ? 'pausada' : f.estado || 'sin-leer';
    const n = { activas: F.filter((f) => f.activa).length, error: F.filter((f) => f.activa && f.estado === 'error').length, aviso: F.filter((f) => f.activa && f.estado === 'aviso').length, pausadas: F.filter((f) => !f.activa).length };
    const lista = F.filter((f) => !filtro || (filtro === 'pausada' ? !f.activa : filtro === 'activa' ? f.activa : f.activa && f.estado === filtro));
    el.innerHTML = `<div class="tarjeta"><h2>Fuentes de datos</h2><p class="mini">Cada fuente lee, en solo lectura, lo que ya junta otro sistema por HTTP (JSON) y lo pasa a telemetría (valores), a eventos de equipos y, a pedido, al historial. Nunca escribe en el origen.</p>
      ${kpis([{ t: 'Activas', n: n.activas, sub: `de ${F.length}`, icono: 'ok', color: '#43a047', k: 'activa', activo: filtro === 'activa' }, { t: 'Con error', n: n.error, sub: 'última lectura', icono: 'alarmas', color: '#e53935', k: 'error', activo: filtro === 'error' },
        { t: 'Con avisos', n: n.aviso, sub: 'datos que no entendió', icono: 'telemetria', color: '#fb8c00', k: 'aviso', activo: filtro === 'aviso' }, { t: 'Pausadas', n: n.pausadas, sub: 'no se leen', icono: 'reloj', color: '#78909c', k: 'pausada', activo: filtro === 'pausada' }])}
      ${tabla([{ t: 'Fuente', html: true, v: (f) => `<b>${h(f.nombre)}</b>${f.descripcion ? `<br><small class="mini">${h(f.descripcion)}</small>` : ''}` }, { t: 'Cada', v: (f) => f.cada_s >= 60 ? `${Math.round(f.cada_s / 60)} min` : `${f.cada_s} s` },
        { t: 'Estado', html: true, v: (f) => { const e = est(f); return e === 'pausada' ? '<span class="et gris">pausada</span>' : e === 'sin-leer' ? '<span class="et gris">sin leer</span>' : `<span class="et ${EST[e][0]}">${EST[e][1]}</span>${f.error ? `<br><small class="mini">${h(f.error.slice(0, 160))}</small>` : ''}`; } },
        { t: 'Última lectura', v: (f) => f.ultimo_ok ? hace(f.ultimo_ok) : 'nunca' }, { t: 'Lecturas', v: (f) => f.lecturas.toLocaleString('es-AR') }, { t: 'Eventos', v: (f) => f.eventos.toLocaleString('es-AR') }, { t: 'Historial', v: (f) => f.historial ? 'sí' : '' }], lista, (f) => editar(f))}
      ${cfg ? `<div class="acciones"><button class="b" id="fN">＋ Nueva fuente</button><button class="b sec" id="fX">${icono('bajar')} Exportar paquete</button><label class="b sec" style="cursor:pointer">Importar paquete<input type="file" id="fI" accept=".json,application/json" hidden></label></div>` : ''}<div id="fM"></div></div><div id="fF"></div>`;
    alClicKpi(el, (k) => { filtro = filtro === k ? '' : k; Nucleo.ir('configuracion'); });
    if (!cfg) return;
    el.querySelector('#fN').onclick = () => editar(null);
    el.querySelector('#fX').onclick = async () => { const x = await api('GET', 'm/fuentes/exportar'); delete x.ok; const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([JSON.stringify(x, null, 1)], { type: 'application/json' }));
      a.download = `fuentes_${new Date().toISOString().slice(0, 10)}.json`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000); };
    el.querySelector('#fI').onchange = async (e) => { const m = el.querySelector('#fM'), fl = e.target.files[0]; if (!fl) return;
      try { const x = JSON.parse(await fl.text()); const q = await api('POST', 'm/fuentes/importar', x); aviso(m, 'ok', `Paquete importado: ${q.nuevas} nuevas y ${q.actualizadas} actualizadas. Quedan como venían (activas o pausadas).`); setTimeout(() => Nucleo.ir('configuracion'), 1500); }
      catch (z) { aviso(m, 'mal', z.message); } e.target.value = ''; };

    function editar(f) {
      const c = el.querySelector('#fF');
      c.innerHTML = `<div class="tarjeta"><h2>${f ? h(f.nombre) : 'Nueva fuente'}</h2>${f ? `<p class="mini">${f.activa ? 'Activa' : 'Pausada'} · última lectura ${f.ultimo_ok ? fecha(f.ultimo_ok) : 'nunca'} · ${f.lecturas} lecturas y ${f.eventos} eventos publicados${f.modificado ? ` · modificada por ${h(f.modificado_por || '')} el ${fecha(f.modificado)}` : ''}</p>` : ''}
        <form class="form" id="fE"><div class="fgrid">
          <div class="fcampo" style="grid-column:span 8"><label>Nombre <b class="req">*</b></label><input name="nombre" required maxlength="80" value="${h(f?.nombre || '')}"></div>
          <div class="fcampo" style="grid-column:span 4"><label>Leer cada (segundos)</label><input name="cada_s" inputmode="numeric" value="${h(f?.cada_s ?? 60)}"></div>
          <div class="fcampo" style="grid-column:span 12"><label>Descripción</label><input name="descripcion" maxlength="300" value="${h(f?.descripcion || '')}"></div>
          <div class="fcampo" style="grid-column:span 12"><label>Dirección (JSON por HTTP) <b class="req">*</b></label><input name="url" required value="${h(f?.url || '')}" placeholder="https://servidor/api/datos.php?action=…"><small class="fayuda">Si la dirección lleva una clave (token, key…), se muestra oculta con •••; dejala así para conservar la guardada.</small></div>
          <div class="fcampo" style="grid-column:span 12"><label>Reglas (JSON) <b class="req">*</b></label><textarea name="reglas" rows="12" style="font-family:monospace;font-size:12px" placeholder='[{"tipo": "objeto", "ruta": "ultimo", "ts": "ts", "activo": "CÓDIGO", "campos": "*"}]'>${h(J(f?.reglas || []))}</textarea>
            <small class="fayuda">Tipos: objeto, lista, filas, series, columnas y eventos. Cada regla dice dónde está cada dato, a qué activo (código PIM) va, el punto, la unidad y el momento.</small></div>
          <div class="fcampo" style="grid-column:span 12"><label>Historial (JSON, opcional)</label><textarea name="historial" rows="6" style="font-family:monospace;font-size:12px" placeholder='{"url": "…{desde_ms}…{hasta_ms}…", "tramo_horas": 24, "max_dias": 32, "reglas": [ … ]}'>${h(J(f?.historial))}</textarea></div>
          <div class="fcampo" style="grid-column:span 12"><label class="fchk"><input type="checkbox" name="activa" ${f?.activa ? 'checked' : ''}> Activa (se lee sola cada tanto)</label></div></div>
          <div class="acciones"><button class="b" type="submit">${f ? 'Guardar cambios' : 'Crear fuente'}</button><button class="b sec" type="button" id="fP">Probar (sin publicar)</button>
            ${f ? `<button class="b sec" type="button" id="fL">Leer ahora</button><button class="b sec" type="button" id="fA">${f.activa ? 'Pausar' : 'Activar'}</button>${f.historial ? `<span style="display:inline-flex;gap:4px;align-items:center"><button class="b sec" type="button" id="fH">Traer historial</button><input id="fD" inputmode="numeric" value="30" style="width:56px" aria-label="Días"> días</span>` : ''}<button class="b peligro" type="button" id="fB">Borrar</button>` : ''}
            <button class="b sec" type="button" id="fC">Cerrar</button></div><div class="fmsg"></div><div id="fR"></div></form></div>`;
      c.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const fe = c.querySelector('#fE'), msg = fe.querySelector('.fmsg'), res = fe.querySelector('#fR');
      const datos = () => { const d = { nombre: fe.nombre.value, descripcion: fe.descripcion.value, url: fe.url.value.trim(), cada_s: parseInt(fe.cada_s.value || '60', 10), activa: fe.activa.checked };
        try { d.reglas = JSON.parse(fe.reglas.value || '[]'); } catch (z) { throw new Error('Reglas: no es un JSON válido (' + z.message + ')'); }
        const hs = fe.historial.value.trim(); try { d.historial = hs ? JSON.parse(hs) : null; } catch (z) { throw new Error('Historial: no es un JSON válido (' + z.message + ')'); }
        if (f) d.id = f.id; return d; };
      fe.onsubmit = async (e) => { e.preventDefault(); try { const d = datos(); f ? await api('PUT', 'm/fuentes/fuentes/' + f.id, d) : await api('POST', 'm/fuentes/fuentes', d); aviso(msg, 'ok', 'Fuente guardada.'); setTimeout(() => Nucleo.ir('configuracion'), 700); } catch (z) { aviso(msg, 'mal', z.message); } };
      c.querySelector('#fC').onclick = () => { c.innerHTML = ''; };
      c.querySelector('#fP').onclick = async () => { try { aviso(msg, 'info', 'Leyendo la dirección…'); const x = await api('POST', 'm/fuentes/probar', datos());
        aviso(msg, x.avisos.length ? 'info' : 'ok', `Se leerían ${x.mediciones} valores de ${x.activos.length} activos y ${x.eventos} eventos.${x.avisos.length ? ' Hay avisos (abajo).' : ''}`);
        res.innerHTML = `${x.avisos.length ? `<div class="aviso info">${x.avisos.slice(0, 30).map(h).join('<br>')}</div>` : ''}<p class="mini">Activos: ${x.activos.map(h).join(', ') || '—'}</p>
          ${tabla([{ t: 'Activo', v: (m) => m.activo }, { t: 'Punto', v: (m) => m.punto }, { t: 'Etiqueta', v: (m) => m.etiqueta || '' }, { t: 'Valor', v: (m) => String(m.valor).replace('.', ',') }, { t: 'Unidad', v: (m) => m.unidad }, { t: 'Momento', v: (m) => m.cuando }], x.muestra)}
          ${x.muestra_eventos.length ? `<h3>Eventos</h3>${tabla([{ t: 'Activo', v: (m) => m.activo }, { t: 'Nivel', v: (m) => m.nivel }, { t: 'Mensaje', v: (m) => m.mensaje }, { t: 'Inicio', v: (m) => m.cuando }], x.muestra_eventos)}` : ''}`; }
        catch (z) { aviso(msg, 'mal', z.message); res.innerHTML = ''; } };
      if (!f) return;
      c.querySelector('#fL').onclick = async () => { try { aviso(msg, 'info', 'Leyendo…'); const x = await api('POST', `m/fuentes/fuentes/${f.id}/leer`); aviso(msg, 'ok', `Leída: ${x.lectura.mediciones} valores nuevos y ${x.lectura.eventos} eventos publicados${x.lectura.avisos?.length ? ` (${x.lectura.avisos.length} avisos)` : ''}.`); } catch (z) { aviso(msg, 'mal', z.message); } };
      c.querySelector('#fA').onclick = async () => { try { await api('POST', `m/fuentes/fuentes/${f.id}/activa`, { activa: !f.activa }); Nucleo.ir('configuracion'); } catch (z) { aviso(msg, 'mal', z.message); } };
      if (c.querySelector('#fH')) c.querySelector('#fH').onclick = async (e) => { const b = e.target; b.disabled = true; aviso(msg, 'info', 'Trayendo el historial: puede tardar unos minutos…');
        try { const x = (await api('POST', `m/fuentes/fuentes/${f.id}/historial`, { dias: parseInt(c.querySelector('#fD').value || '30', 10) })).historial;
          aviso(msg, x.errores?.length ? 'info' : 'ok', `Historial de ${x.dias} días: ${x.llamadas} consultas, ${x.series} señales, ${x.lecturas.toLocaleString('es-AR')} lecturas nuevas y ${x.eventos} eventos.${x.errores?.length ? ' Errores: ' + x.errores.slice(0, 5).join(' · ') : ''}`); }
        catch (z) { aviso(msg, 'mal', z.message); } b.disabled = false; };
      const bb = c.querySelector('#fB'); bb.onclick = async () => { if (!bb.dataset.ok) { bb.dataset.ok = '1'; bb.textContent = 'Tocá de nuevo para borrar'; return; }
        try { await api('POST', `m/fuentes/fuentes/${f.id}/borrar`, {}); Nucleo.ir('configuracion'); } catch (z) { aviso(msg, 'mal', z.message); } };
    }
  } });
})();
