<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Ola P (P4): el armado del .docx pasó al núcleo en la Ola 4 (\Nucleo\docx), para que lo usen todos los módulos (p. ej. el informe de energía de paneles).
declare(strict_types=1);

namespace Modulos\Reportes;

function docx(array $m): string { return \Nucleo\docx($m); }
