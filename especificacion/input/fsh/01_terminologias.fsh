// SPDX-License-Identifier: CC-BY-SA-4.0
// Copyright (C) 2026 Ovidio Francisco Arias
// ===== Sistemas de códigos del PIM (0.9.2: 11 de dominio + auxiliar + vínculo + 5 de conformidad y origen) =====

CodeSystem: PimCategoriaAlcance
Id: pim-categoria-alcance
Title: "PIM — Categoría de alcance"
Description: "Categorías A a D del alcance del PIM (PIM-PROP-001, sección 3.3)."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #A "Producto médico activo" "Producto médico activo en uso (Ley 26.906; Disp. ANMAT 64/2025)."
* #B "Instalación específica de salud" "Instalación regida por normas propias de los establecimientos de salud o que sostiene directamente un local de uso médico o un PMA."
* #C "Instalación general que sostiene servicios críticos" "Instalación general que cumple al menos un supuesto del criterio de inclusión de la sección 3.3."
* #D "Instalación general" "Instalación presente en el establecimiento, no propia de un entorno de salud. Intercambio opcional."

CodeSystem: PimClaseRiesgoPM
Id: pim-clase-riesgo-pm
Title: "PIM — Clase de riesgo del producto médico"
Description: "Clases de riesgo de los productos médicos según la Disp. ANMAT 64/2025 (Res. GMC 25/21)."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #I "Clase I" "Clase de riesgo I."
* #II "Clase II" "Clase de riesgo II."
* #III "Clase III" "Clase de riesgo III."
* #IV "Clase IV" "Clase de riesgo IV."

CodeSystem: PimGrupoLocal
Id: pim-grupo-local
Title: "PIM — Grupo de uso médico del local"
Description: "Grupos de locales para usos médicos según AEA 90364-7-710:2024 [a confirmar la nomenclatura exacta con el texto oficial]."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #0 "Grupo 0" "Local sin aplicación de partes aplicables."
* #1 "Grupo 1" "Local con partes aplicables externas o invasivas no intracardíacas."
* #2a "Grupo 2a" "Local de grupo 2, variante a."
* #2b "Grupo 2b" "Local de grupo 2, variante b: procedimientos intracardíacos u operaciones vitales."

CodeSystem: PimCategoriaRiesgo
Id: pim-categoria-riesgo
Title: "PIM — Categoría de riesgo ante la falla"
Description: "Categorías de riesgo de NFPA 99, capítulo 4."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #1 "Categoría 1" "La falla puede causar lesión grave o muerte."
* #2 "Categoría 2" "La falla puede causar lesión menor."
* #3 "Categoría 3" "La falla puede causar molestias."
* #4 "Categoría 4" "La falla no tiene impacto en la atención."

CodeSystem: PimMetodoRiesgo
Id: pim-metodo-riesgo
Title: "PIM — Método de evaluación de riesgo"
Description: "Métodos admitidos para evaluar el riesgo de un activo."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #fennigkoh-smith "Fennigkoh y Smith (1989)" "Método de puntaje por función, riesgo físico y requisitos de mantenimiento."
* #nfpa99 "NFPA 99" "Categoría de riesgo según NFPA 99."
* #iru "Índice de Riesgo Unificado" "IRU = F + R + M + A (PIM-PROP-001, sección 8.2) [propuesta]."

CodeSystem: PimTipoMantenimiento
Id: pim-tipo-mantenimiento
Title: "PIM — Tipo de mantenimiento"
Description: "Tipos de mantenimiento basados en EN 13306, más códigos propios del PIM."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #correctivo-inmediato "Correctivo inmediato" "Corrección sin demora después de detectar la falla."
* #correctivo-diferido "Correctivo diferido" "Corrección postergada según reglas de prioridad."
* #preventivo-sistematico "Preventivo sistemático" "Preventivo según intervalos de tiempo o de uso."
* #preventivo-condicional "Preventivo condicional" "Preventivo basado en la condición del activo."
  * #predictivo "Predictivo" "Preventivo condicional basado en la proyección de la condición."
* #mejora "Mejora" "Acción para mejorar la confiabilidad o la seguridad sin cambiar la función."
* #ensayo-reglamentario "Ensayo reglamentario" "Inspección o prueba exigida por una norma (código PIM)."
* #verificacion-tecnica-pma "Verificación técnica de PMA" "Verificación de aptitud técnica de un PMA (Ley 26.906; Dec. 517/2023, art. 9) (código PIM)."

CodeSystem: PimEstadoOrden
Id: pim-estado-orden
Title: "PIM — Estado de negocio de la orden"
Description: "Estados de la máquina de estados de la orden de trabajo (PIM-PROP-001, Tabla 9-3)."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #solicitada "Solicitada" "Alta desde una solicitud o un plan."
* #en-revision "En revisión" "Se verifica la prioridad."
* #asignada "Asignada" "Tiene empresa o área y responsable."
* #aceptada "Aceptada" "El ejecutor la tomó y comprometió un plazo."
* #lista "Lista" "Recursos y acceso disponibles."
* #en-ejecucion "En ejecución" "Trabajo en curso."
* #en-espera "En espera" "Detenida con motivo."
* #finalizada "Finalizada" "Trabajo terminado con evidencia."
* #conformada "Conformada" "Conformidad del servicio usuario."
* #rechazada "Rechazada" "No se ejecutará, con motivo."
* #anulada "Anulada" "Cancelada, con motivo. Sin borrado físico."

CodeSystem: PimModoFalla
Id: pim-modo-falla
Title: "PIM — Modo de falla"
Description: "Modos de falla basados en ISO 14224:2016, Anexo B. Contenido parcial: cada código se confirma con el texto oficial antes de publicar."
* ^status = #draft
* ^caseSensitive = true
* ^content = #fragment
* #FTS "No arranca cuando se lo requiere" "Ejemplo inicial [a confirmar con ISO 14224]."
* #ELP "Fuga externa de fluido de proceso" "Ejemplo inicial [a confirmar con ISO 14224]."
* #VIB "Vibración alta" "Ejemplo inicial [a confirmar con ISO 14224]."
* #OHE "Sobrecalentamiento" "Ejemplo inicial [a confirmar con ISO 14224]."

CodeSystem: PimEnsayo
Id: pim-ensayo
Title: "PIM — Ensayos reglamentarios y verificaciones técnicas"
Description: "Contenido inicial del catálogo de ensayos (PIM-PROP-001, Tabla 8-2). Cada código cita la norma que lo exige."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #VT-PMA "Verificación técnica de PMA" "Seguridad eléctrica y desempeño (Ley 26.906; Dec. 517/2023, art. 9)."
* #IT-MON "Monitor de aislamiento de red IT" "Verificación del monitor y sus alarmas (AEA 90364-7-710:2024)."
* #GM-PRES "Gases medicinales" "Presión, pureza, alarmas y válvulas de zona (ISO 7396-1)."
* #SC-DP "Salas controladas" "Presión diferencial, renovaciones por hora y partículas (ISO 14644)."
* #ES-VAL "Esterilización" "Validación y recalificación de esterilizadores (ISO 17665)."
* #AD-CAL "Agua para diálisis" "Calidad del agua y del fluido para hemodiálisis (ISO 23500-1:2024)."
* #PC-RES "Pisos conductivos" "Resistencia de pisos conductivos o disipativos [norma a confirmar]."
* #GE-CARGA "Grupo electrógeno" "Prueba con carga y tiempo de transferencia (AEA 90364-7-710; NFPA 110)."
* #UPS-AUT "UPS" "Autonomía y transferencia de UPS de locales médicos."
* #IN-RED "Incendio" "Red de incendio y detección (normativa local)."

CodeSystem: PimSistemaCS
Id: pim-sistema
Title: "PIM — Clase de sistema de instalación"
Description: "Clases de sistemas de instalación (PIM-PROP-001, Anexo B)."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #GAS-O2 "Oxígeno medicinal"
* #GAS-AIRE "Aire medicinal"
* #GAS-VAC "Vacío medicinal"
* #GAS-N2O "Óxido nitroso"
* #GAS-CO2 "Dióxido de carbono"
* #GAS-AGSS "Evacuación de gases anestésicos"
* #ELE-ITM "Red IT médica con monitor de aislamiento"
* #HVAC-QX "Climatización de quirófanos y áreas críticas"
* #HVAC-AIS "Aislamientos con presión positiva o negativa"
* #FIL-HEPA "Filtración absoluta"
* #EST-VAP "Esterilización y generación de vapor"
* #AGU-DIAL "Tratamiento de agua para hemodiálisis"
* #AGU-OSM "Circuitos de ósmosis"
* #PIS-COND "Pisos conductivos o disipativos"
* #ELE-EMER "Energía de emergencia"
* #ELE-UPS "UPS de locales médicos o PMA"
* #TIC-CPD "Centro de datos con sistemas clínicos"
* #INC-DET "Detección de incendio"
* #INC-EXT "Extinción de incendio"
* #FRIO-CONS "Frío de conservación"
* #ELE-NORM "Distribución eléctrica normal e iluminación"
* #HVAC-GRAL "Climatización de confort"
* #AGU-SAN "Agua sanitaria"
* #DES-CLO "Desagües cloacales y pluviales"
* #EDI-GRAL "Edificio y obra civil"
* #EDI-ABE "Aberturas"
* #TRV-ASC "Transporte vertical"
* #COM-LLAM "Llamado de enfermería" "Sistema de llamado paciente-enfermería (categoría B)."
* #GAS-NAT "Gas natural" "Red de gas natural del establecimiento (categoría D salvo que alimente esterilización o calderas de servicios críticos)."

CodeSystem: PimPuntoCS
Id: pim-punto
Title: "PIM — Tipo de punto de medición"
Description: "Tipos de punto de medición; se mapean a clases de Brick y etiquetas de Project Haystack."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #PRES-LINEA "Presión de línea"
* #PRES-DIF "Presión diferencial"
* #TEMP-IMP "Temperatura de impulsión"
* #HUM "Humedad relativa"
* #AISL "Resistencia de aislamiento"
* #TRANSF "Estado de transferencia"
* #TEMP-AMB "Temperatura ambiente"
* #TEMP-ACEITE "Temperatura de aceite"
* #TENSION-L1 "Tensión de fase L1"
* #CORRIENTE "Corriente"
* #CAUDAL "Caudal"
* #NIVEL "Nivel de tanque o reservorio"
* #CONDUCTIVIDAD "Conductividad del agua"
* #PARTICULAS "Concentración de partículas"
* #ALIMENTACION "Fuente de alimentación (red o batería)"
* #BATERIA "Carga de batería"
* #AUTOPRUEBA "Resultado de autoprueba"

// Valores de extensiones: un único sistema auxiliar agrupado
CodeSystem: PimValores
Id: pim-valores
Title: "PIM — Valores de extensiones"
Description: "Sistema auxiliar con los valores codificados de las extensiones PIM, agrupados por extensión. Cada grupo se publica como un conjunto de valores."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* ^hierarchyMeaning = #grouped-by
* #banda-iru "Banda IRU" "Grupo: bandas de prioridad del IRU."
  * #banda-A "Banda A" "IRU ≥ 18."
  * #banda-B "Banda B" "IRU 14 a 17."
  * #banda-C "Banda C" "IRU 10 a 13."
  * #banda-D "Banda D" "IRU < 10."
* #estado-declarado "Estado declarado" "Grupo: estado del activo declarado por el solicitante."
  * #operativo-con-falla "Operativo con falla"
  * #degradado "Degradado"
  * #fuera-de-servicio "Fuera de servicio"
* #presion-relativa "Presión relativa" "Grupo: presión del local respecto de los adyacentes."
  * #positiva "Positiva"
  * #negativa "Negativa"
  * #neutra "Neutra"
* #redundancia "Redundancia" "Grupo: redundancia del sistema."
  * #N "N"
  * #N+1 "N+1"
  * #2N "2N"
* #conformidad "Conformidad" "Grupo: resultado de un ensayo o de una conformidad."
  * #conforme "Conforme"
  * #no-conforme "No conforme"
  * #conforme-con-observaciones "Conforme con observaciones"
* #pertenencia "Pertenencia" "Grupo: pertenencia del PMA. Los valores definitivos son los del desplegable de SISA [a confirmar con la DNSIS]."
  * #desconocida "Desconocida" "Valor transitorio para la migración."

CodeSystem: PimTipoVinculo
Id: pim-tipo-vinculo
Title: "PIM — Tipo de vínculo entre nodos"
Description: "Tipos de vínculo institucional entre nodos de la red colmena [propuesta para la versión 0.9.2]."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #local "Local" "Nodos de una misma institución."
* #institucional "Institucional" "Instituciones distintas."
* #central "Central" "Un organismo central y los actores que admite."

// ===== Conjuntos de valores =====
ValueSet: PimCategoriaAlcanceVS
Id: pim-categoria-alcance
Title: "PIM — Categoría de alcance (VS)"
Description: "Todas las categorías de alcance."
* ^status = #draft
* include codes from system PimCategoriaAlcance

ValueSet: PimClaseRiesgoPMVS
Id: pim-clase-riesgo-pm
Title: "PIM — Clase de riesgo del producto médico (VS)"
Description: "Clases I a IV."
* ^status = #draft
* include codes from system PimClaseRiesgoPM

ValueSet: PimGrupoLocalVS
Id: pim-grupo-local
Title: "PIM — Grupo de uso médico (VS)"
Description: "Grupos 0, 1, 2a y 2b."
* ^status = #draft
* include codes from system PimGrupoLocal

ValueSet: PimCategoriaRiesgoVS
Id: pim-categoria-riesgo
Title: "PIM — Categoría de riesgo (VS)"
Description: "Categorías 1 a 4."
* ^status = #draft
* include codes from system PimCategoriaRiesgo

ValueSet: PimMetodoRiesgoVS
Id: pim-metodo-riesgo
Title: "PIM — Método de riesgo (VS)"
Description: "Métodos de evaluación de riesgo."
* ^status = #draft
* include codes from system PimMetodoRiesgo

ValueSet: PimTipoMantenimientoVS
Id: pim-tipo-mantenimiento
Title: "PIM — Tipo de mantenimiento (VS)"
Description: "Tipos de mantenimiento."
* ^status = #draft
* include codes from system PimTipoMantenimiento

ValueSet: PimEstadoOrdenVS
Id: pim-estado-orden
Title: "PIM — Estado de la orden (VS)"
Description: "Estados de negocio de la orden."
* ^status = #draft
* include codes from system PimEstadoOrden

ValueSet: PimModoFallaVS
Id: pim-modo-falla
Title: "PIM — Modo de falla (VS)"
Description: "Modos de falla."
* ^status = #draft
* include codes from system PimModoFalla

ValueSet: PimEnsayoVS
Id: pim-ensayo
Title: "PIM — Ensayos (VS)"
Description: "Ensayos reglamentarios y verificaciones técnicas."
* ^status = #draft
* include codes from system PimEnsayo

ValueSet: PimSistemaVS
Id: pim-sistema
Title: "PIM — Clase de sistema (VS)"
Description: "Clases de sistemas de instalación."
* ^status = #draft
* include codes from system PimSistemaCS

ValueSet: PimPuntoVS
Id: pim-punto
Title: "PIM — Tipo de punto (VS)"
Description: "Tipos de punto de medición."
* ^status = #draft
* include codes from system PimPuntoCS

ValueSet: PimTipoVinculoVS
Id: pim-tipo-vinculo
Title: "PIM — Tipo de vínculo (VS)"
Description: "Tipos de vínculo entre nodos."
* ^status = #draft
* include codes from system PimTipoVinculo

ValueSet: PimBandaIruVS
Id: pim-banda-iru
Title: "PIM — Banda IRU"
Description: "Bandas de prioridad del IRU."
* ^status = #draft
* include codes from system PimValores where concept descendent-of #banda-iru

ValueSet: PimEstadoDeclaradoVS
Id: pim-estado-declarado
Title: "PIM — Estado declarado"
Description: "Estado del activo declarado por el solicitante."
* ^status = #draft
* include codes from system PimValores where concept descendent-of #estado-declarado

ValueSet: PimPresionRelativaVS
Id: pim-presion-relativa
Title: "PIM — Presión relativa"
Description: "Presión del local respecto de los adyacentes."
* ^status = #draft
* include codes from system PimValores where concept descendent-of #presion-relativa

ValueSet: PimRedundanciaVS
Id: pim-redundancia
Title: "PIM — Redundancia"
Description: "Redundancia del sistema."
* ^status = #draft
* include codes from system PimValores where concept descendent-of #redundancia

ValueSet: PimConformidadVS
Id: pim-conformidad
Title: "PIM — Conformidad"
Description: "Resultado de un ensayo o conformidad."
* ^status = #draft
* include codes from system PimValores where concept descendent-of #conformidad

ValueSet: PimPertenenciaVS
Id: pim-pertenencia
Title: "PIM — Pertenencia del PMA"
Description: "Pertenencia del PMA (REFES). Valores definitivos a confirmar con la DNSIS."
* ^status = #draft
* include codes from system PimValores where concept descendent-of #pertenencia

// ===== 0.9.2: conformidad en formato IHE, origen y códigos MDC usados por MEM-DMC =====
Alias: $MDC = urn:iso:std:iso:11073:10101

CodeSystem: PimNivelConformidad
Id: pim-nivel-conformidad
Title: "PIM — Nivel de conformidad"
Description: "Niveles acumulativos de conformidad (Parte 6)."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #basico "Básico" "PIM-INV + PIM-SOL + PIM-OT."
* #intermedio "Intermedio" "Básico + PIM-REG, PIM-RSG, PIM-PRV, PIM-ENS y PIM-KPI."
* #avanzado "Avanzado" "Intermedio + PIM-TEL, PIM-ALR, PIM-FED y PIM-EQM."

CodeSystem: PimPerfilIntegracion
Id: pim-perfil-integracion
Title: "PIM — Perfiles de integración"
Description: "Perfiles de integración del PIM (Parte 4). Cada uno es una «definición de flujo de trabajo» en el sentido de IHE XDW, montada sobre FHIR R4."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #PIM-INV "Inventario" "Organismos, establecimientos, locales, activos y puntos de medición. Agrupa IHE ITI mCSD."
* #PIM-REG "Registro estatal" "Datos de PMA exigidos por el REFES (Res. SGS 532/2026)."
* #PIM-SOL "Solicitud" "Pedido de intervención sobre un local o un activo."
* #PIM-OT "Orden de trabajo" "Ciclo de la orden entre organismos (máquina de estados)."
* #PIM-RSG "Riesgo y prioridad" "IRU explicable."
* #PIM-PRV "Preventivo" "Planes y protocolos."
* #PIM-ENS "Ensayo reglamentario" "Informe de ensayo o verificación técnica con acta firmada."
* #PIM-KPI "Indicadores" "Definición y valores de indicadores (EN 15341)."
* #PIM-TEL "Telemetría" "Mediciones de instalaciones."
* #PIM-ALR "Alarmas técnicas" "Aviso y escalamiento. Puente con IHE DEV ACM."
* #PIM-FED "Federación" "Sincronización entre nodos. Semántica de IHE ITI-91 extendida a todos los recursos."
* #PIM-EQM "Equipos médicos" "Puente con IHE DEV MEM-DMC (PCD-15) y MEM-LS (PCD-16)."

CodeSystem: PimActor
Id: pim-actor
Title: "PIM — Actores"
Description: "Actores del marco técnico PIM. En la colmena, cada actor cliente corresponde a un rol PIM."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #GM "Gestor de mantenimiento" "Sistema que custodia el inventario y las órdenes (servidor PIM). Se agrupa con IHE mCSD Directory y, opcionalmente, con IHE MEM DMIC y LOC."
* #SO "Solicitante" "Sistema o aplicación que registra solicitudes."
* #EJ "Ejecutor" "Empresa o área que ejecuta órdenes de su organización."
* #FT "Fuente de telemetría" "Gateway, PLC o sensor que envía mediciones."
* #CI "Consumidor de información e indicadores" "Sistema que consulta inventario, órdenes, riesgo e indicadores (ministerio, dirección del hospital). Se agrupa con IHE mCSD Query Client."
* #DA "Destinatario de alarmas" "Recibe y gestiona avisos técnicos (guardia técnica, mensajería). Se agrupa con IHE ACM Alert Communicator."
* #RE "Registro estatal" "Registro del Estado que recibe datos de PMA (REFES)."
* #DI "Directorio" "Publica los organismos, establecimientos, locales y endpoints. Es el actor Directory de IHE mCSD."

CodeSystem: PimOpcion
Id: pim-opcion
Title: "PIM — Opciones de los perfiles de integración"
Description: "Opciones que un actor puede declarar además de lo obligatorio."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #mqtt "MQTT" "PIM-TEL: mediciones por MQTT 3.1.1 además de REST."
* #colmena "Colmena" "PIM-FED y PIM-OT entre nodos por el protocolo colmena/1 (sobres firmados y sellados)."
* #mem-dmc "MEM-DMC" "PIM-EQM: recibe IHE PCD-15 (ORU^R44) por MLLP."
* #mem-ls "MEM-LS" "PIM-EQM: recibe IHE PCD-16 (ORU^R45) por MLLP."
* #mcsd-update "Actualización mCSD" "PIM-INV: responde IHE ITI-91 (_history con _since) para Organization y Location."
* #transicion "Operación $transicion" "PIM-OT: cambios de estado por operación, además de PUT."

CodeSystem: PimOrigen
Id: pim-origen
Title: "PIM — Origen del dato"
Description: "Etiqueta (meta.tag) que indica de dónde viene un recurso."
* ^status = #draft
* ^caseSensitive = true
* ^content = #complete
* #pim "PIM" "Creado en el servidor PIM."
* #legado "Sistema anterior" "Reflejado de un sistema anterior: solo lectura en PIM."
* #mem-dmc "MEM-DMC" "Creado a partir de un mensaje IHE PCD-15."

ValueSet: PimNivelConformidadVS
Id: pim-nivel-conformidad
Title: "PIM — Nivel de conformidad (VS)"
Description: "Niveles de conformidad."
* ^status = #draft
* include codes from system PimNivelConformidad

ValueSet: PimPerfilIntegracionVS
Id: pim-perfil-integracion
Title: "PIM — Perfiles de integración (VS)"
Description: "Perfiles de integración."
* ^status = #draft
* include codes from system PimPerfilIntegracion

ValueSet: PimActorVS
Id: pim-actor
Title: "PIM — Actores (VS)"
Description: "Actores del marco técnico."
* ^status = #draft
* include codes from system PimActor

ValueSet: PimOpcionVS
Id: pim-opcion
Title: "PIM — Opciones (VS)"
Description: "Opciones de los perfiles de integración."
* ^status = #draft
* include codes from system PimOpcion

ValueSet: PimOrigenVS
Id: pim-origen
Title: "PIM — Origen del dato (VS)"
Description: "Origen del dato."
* ^status = #draft
* include codes from system PimOrigen

ValueSet: PimMemDmcVS
Id: pim-mem-dmc
Title: "PIM — Códigos MDC de IHE MEM-DMC y MEM-LS"
Description: "Códigos de la nomenclatura ISO/IEEE 11073-10101 (MDC) que usan los mensajes IHE PCD-15 y PCD-16 (suplemento MEM-DMC Rev. 1.5). Los atributos de batería y autoprueba no tienen código asignado en el suplemento: llegan con código 0 y prefijo MDCX, y se conservan como texto."
* ^status = #draft
* $MDC#69135 "MDC_OBS_MEM"
* $MDC#67880 "MDC_ATTR_ID_MODEL"
* $MDC#67972 "MDC_ATTR_SYS_ID"
* $MDC#67925 "MDC_ATTR_POWER_STAT"
* $MDC#68512 "MDC_ATTR_LS_NAME"
* $MDC#68513 "MDC_ATTR_LS_LOCATION"
* $MDC#68525 "MDC_ATTR_LS_COORD_X"
* $MDC#68526 "MDC_ATTR_LS_COORD_Y"
* $MDC#68527 "MDC_ATTR_LS_COORD_Z"

ValueSet: PimPuntoMedicionVS
Id: pim-punto-medicion
Title: "PIM — Puntos de medición (instalaciones y equipos médicos)"
Description: "Tipos de punto: códigos PIM para instalaciones y códigos MDC para los atributos que informan los equipos médicos por IHE MEM-DMC."
* ^status = #draft
* include codes from system PimPuntoCS
* include codes from valueset PimMemDmcVS
