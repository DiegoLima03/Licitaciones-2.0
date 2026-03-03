<?php

declare(strict_types=1);

// Espera variables:
// - ?array $licitacion  Datos de la licitación (o null si no existe).
// - ?string $error      Mensaje de error opcional.

ob_start();
?>
<div class="flex items-center gap-2 text-sm text-slate-500">
  <a href="/licitaciones" class="inline-flex items-center gap-1 text-slate-500 hover:text-slate-700">
    <span>&larr;</span>
    <span>Volver al listado</span>
  </a>
</div>

<div class="mt-4 space-y-4">
  <?php if (isset($error) && $error !== null): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      <?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
    </div>
  <?php elseif ($licitacion === null): ?>
    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
      No se encontró la licitación solicitada.
    </div>
  <?php else: ?>
    <header class="flex flex-col gap-2">
      <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
          <p class="text-xs uppercase tracking-wide text-slate-400">
            Nº expediente <?php echo htmlspecialchars((string)($licitacion['numero_expediente'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </p>
          <h1 class="truncate text-2xl font-semibold text-slate-900">
            <?php echo htmlspecialchars((string)($licitacion['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </h1>
        </div>
        <div class="flex flex-col items-end gap-2">
          <span class="inline-flex items-center rounded-full bg-slate-900 px-3 py-1 text-xs font-medium text-slate-100 shadow-sm">
            <?php echo htmlspecialchars((string)($licitacion['tipo_procedimiento'] ?? 'ORDINARIO'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
          </span>
          <?php
          $estadoIdActual = (int)($licitacion['id_estado'] ?? 0);
          $estadosEnum = [
              2 => 'Descartada',
              3 => 'En análisis',
              4 => 'Presentada',
              5 => 'Adjudicada',
              6 => 'No adjudicada',
              7 => 'Terminada',
          ];
          ?>
          <form
            id="form-estado"
            action="/licitaciones/<?php echo (int)($licitacion['id_licitacion'] ?? 0); ?>/estado"
            method="POST"
          >
            <select
              name="estado"
              class="min-w-[130px] justify-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700 shadow-sm"
            >
              <?php foreach ($estadosEnum as $idEstado => $nombreEstado): ?>
                <option
                  value="<?php echo $idEstado; ?>"
                  <?php echo $estadoIdActual === $idEstado ? 'selected' : ''; ?>
                >
                  <?php echo htmlspecialchars($nombreEstado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>
      <p class="text-sm text-slate-500">
        Detalle de la licitación, presupuesto, ejecución real y remaining.
      </p>
    </header>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var selectEstado = document.querySelector('#form-estado select[name="estado"]');
      if (!selectEstado) return;
      selectEstado.addEventListener('change', function () {
        var form = this.closest('form');
        if (form) {
          form.submit();
        }
      });
    });
    </script>

    <div class="mt-4 grid gap-4 md:grid-cols-3">
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Cliente</p>
        <p class="mt-1 text-sm font-medium text-slate-900">
          <?php echo htmlspecialchars((string)($licitacion['pais'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </p>
        <p class="mt-1 text-xs text-slate-500">
          Nº expediente: <?php echo htmlspecialchars((string)($licitacion['numero_expediente'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </p>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Fechas</p>
        <dl class="mt-1 space-y-1 text-xs text-slate-600">
          <div class="flex items-center justify-between gap-2">
            <dt>Presentación</dt>
            <dd class="font-medium">
              <?php
              $fp = (string)($licitacion['fecha_presentacion'] ?? '');
              if ($fp !== '' && str_contains($fp, ' ')) {
                  $fp = explode(' ', $fp)[0];
              }
              echo $fp !== '' ? htmlspecialchars($fp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—';
              ?>
            </dd>
          </div>
          <div class="flex items-center justify-between gap-2">
            <dt>Adjudicación</dt>
            <dd class="font-medium">
              <?php
              $fa = (string)($licitacion['fecha_adjudicacion'] ?? '');
              if ($fa !== '' && str_contains($fa, ' ')) {
                  $fa = explode(' ', $fa)[0];
              }
              echo $fa !== '' ? htmlspecialchars($fa, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—';
              ?>
            </dd>
          </div>
          <div class="flex items-center justify-between gap-2">
            <dt>Finalización</dt>
            <dd class="font-medium">
              <?php
              $ff = (string)($licitacion['fecha_finalizacion'] ?? '');
              if ($ff !== '' && str_contains($ff, ' ')) {
                  $ff = explode(' ', $ff)[0];
              }
              echo $ff !== '' ? htmlspecialchars($ff, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—';
              ?>
            </dd>
          </div>
        </dl>
      </div>
      <div class="rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Presupuesto base</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900">
          <?php echo number_format((float)($licitacion['pres_maximo'] ?? 0), 0, ',', '.'); ?> €
        </p>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php

$content = ob_get_clean();
$title = 'Detalle licitación';

require __DIR__ . '/../layout.php';

