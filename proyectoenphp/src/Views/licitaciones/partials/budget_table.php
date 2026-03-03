<?php

declare(strict_types=1);

/**
 * Parcial de tabla de presupuesto para una licitación, versión PHP del componente
 * React `EditableBudgetTable`.
 *
 * Espera:
 * - array $licitacion con al menos:
 *   - int   id_licitacion
 *   - array detalles => lista de partidas (cada una con campos como:
 *       id_detalle, product_nombre / nombre_producto_libre, lote, unidades, pvu, pcu, pmaxu)
 */

/** @var array $licitacion */
$detalles = isset($licitacion['detalles']) && is_array($licitacion['detalles'])
    ? $licitacion['detalles']
    : [];

$idLicitacion = (int)($licitacion['id_licitacion'] ?? 0);

// Reglas de visualización de columnas (idénticas al componente original)
$idTipo = (int)($licitacion['id_tipolicitacion'] ?? 0);
$isTipo2 = $idTipo === 2;
$isTipo4 = $idTipo === 4;
$isTipo5 = $idTipo === 5;
$showPmaxu = in_array($idTipo, [1, 2, 4, 5], true);
$showUnidades = !in_array($idTipo, [2, 4], true);

?>

<form
  action="/licitaciones/<?php echo $idLicitacion; ?>/presupuesto"
  method="POST"
  class="mt-4"
>
  <input type="hidden" name="id_licitacion" value="<?php echo $idLicitacion; ?>">

  <div class="flex min-h-[500px] w-full flex-1 flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
    <div class="flex-1 overflow-auto">
      <table class="min-w-full text-left text-sm">
        <thead class="sticky top-0 z-10 bg-slate-50 shadow-sm">
          <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <th class="min-w-[280px] py-3 pl-4 pr-2 font-medium">Producto</th>
            <?php if ($showUnidades): ?>
              <th class="w-24 py-3 pr-2 text-right font-medium">Uds.</th>
            <?php endif; ?>
            <?php if ($showPmaxu): ?>
              <th class="w-28 py-3 pr-2 text-right font-medium">PMAXU (€)</th>
            <?php endif; ?>
            <th class="w-28 py-3 pr-2 text-right font-medium">PVU (€)</th>
            <th class="w-28 py-3 pr-2 text-right font-medium">PCU (€)</th>
            <th class="w-24 py-3 pr-2 text-right font-medium">Beneficio</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100" id="budget-tbody-<?php echo $idLicitacion; ?>">
          <?php foreach ($detalles as $index => $producto): ?>
            <?php
              $nombre = (string)($producto['product_nombre'] ?? ($producto['nombre_producto_libre'] ?? ''));
              $uds    = (float)($producto['unidades'] ?? 0);
              $pmaxu  = (float)($producto['pmaxu'] ?? 0);
              $pvu    = (float)($producto['pvu'] ?? 0);
              $pcu    = (float)($producto['pcu'] ?? 0);
              $beneficio = $pvu - $pcu;
            ?>
            <tr class="hover:bg-slate-50">
              <td class="min-w-[280px] py-2 pl-4 pr-2 align-middle">
                <input
                  type="hidden"
                  name="productos[<?php echo $index; ?>][id_detalle]"
                  value="<?php echo (int)($producto['id_detalle'] ?? 0); ?>"
                />
                <input
                  type="text"
                  name="productos[<?php echo $index; ?>][concepto]"
                  value="<?php echo htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                  class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                  placeholder="Descripción / producto"
                />
              </td>

              <?php if ($showUnidades): ?>
                <td class="py-2 pr-2 text-right align-middle">
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="productos[<?php echo $index; ?>][unidades]"
                    value="<?php echo $uds > 0 ? htmlspecialchars((string)$uds, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>"
                    class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                    placeholder="0"
                  />
                </td>
              <?php endif; ?>

              <?php if ($showPmaxu): ?>
                <td class="py-2 pr-2 text-right align-middle">
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="productos[<?php echo $index; ?>][pmaxu]"
                    value="<?php echo $pmaxu > 0 ? htmlspecialchars((string)$pmaxu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>"
                    class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                    placeholder="0"
                  />
                </td>
              <?php endif; ?>

              <td class="py-2 pr-2 text-right align-middle">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  name="productos[<?php echo $index; ?>][pvu]"
                  value="<?php echo $pvu > 0 ? htmlspecialchars((string)$pvu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>"
                  class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                  placeholder="0"
                />
              </td>

              <td class="py-2 pr-2 text-right align-middle">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  name="productos[<?php echo $index; ?>][pcu]"
                  value="<?php echo $pcu > 0 ? htmlspecialchars((string)$pcu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>"
                  class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                  placeholder="0"
                />
              </td>

              <td class="py-2 pr-2 text-right align-middle">
                <span class="<?php
                  echo $beneficio > 0
                    ? 'font-medium text-emerald-600'
                    : ($beneficio < 0 ? 'font-medium text-red-600' : 'text-slate-500');
                ?>">
                  <?php echo number_format($beneficio, 2, ',', '.'); ?> €
                </span>
              </td>
            </tr>
          <?php endforeach; ?>

          <!-- Fila fantasma para añadir un nuevo producto -->
          <tr class="bg-emerald-50/20 hover:bg-emerald-50">
            <td class="min-w-[280px] py-2 pl-4 pr-2 align-middle">
              <input
                type="text"
                name="productos[new][concepto]"
                class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                placeholder="Añadir nuevo concepto…"
              />
            </td>

            <?php if ($showUnidades): ?>
              <td class="py-2 pr-2 text-right align-middle">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  name="productos[new][unidades]"
                  class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                  placeholder="0"
                />
              </td>
            <?php endif; ?>

            <?php if ($showPmaxu): ?>
              <td class="py-2 pr-2 text-right align-middle">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  name="productos[new][pmaxu]"
                  class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                  placeholder="0"
                />
              </td>
            <?php endif; ?>

            <td class="py-2 pr-2 text-right align-middle">
              <input
                type="number"
                step="0.01"
                min="0"
                name="productos[new][pvu]"
                class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                placeholder="0"
              />
            </td>

            <td class="py-2 pr-2 text-right align-middle">
              <input
                type="number"
                step="0.01"
                min="0"
                name="productos[new][pcu]"
                class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"
                placeholder="0"
              />
            </td>

            <td class="py-2 pr-2 text-right align-middle">
              <span class="text-xs text-slate-400">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="border-t border-slate-100 bg-slate-50 px-4 py-2 flex items-center justify-between gap-3">
      <span class="text-xs text-slate-400">
        Al pulsar "Guardar todo" se enviarán todas las líneas al servidor para actualizar el presupuesto.
      </span>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center rounded-md bg-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 js-add-product"
        >
          Añadir producto
        </button>
        <button
          type="submit"
          class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
        >
          Guardar todo
        </button>
      </div>
    </div>
  </div>
</form>

<script>
(function () {
  var form = document.querySelector('form[action="/licitaciones/<?php echo $idLicitacion; ?>/presupuesto"]');
  if (!form) return;

  var tbody = document.getElementById('budget-tbody-<?php echo $idLicitacion; ?>');
  var addBtn = form.querySelector('.js-add-product');
  if (!tbody || !addBtn) return;

  var showUnidades = <?php echo $showUnidades ? 'true' : 'false'; ?>;
  var showPmaxu = <?php echo $showPmaxu ? 'true' : 'false'; ?>;

  // Índice incremental para nuevas filas
  var newIndex = 1;

  function attachBenefitListeners(row) {
    var pvuInput = row.querySelector('input[data-role="pvu"]');
    var pcuInput = row.querySelector('input[data-role="pcu"]');
    var beneficioSpan = row.querySelector('[data-role="beneficio"]');
    if (!pvuInput || !pcuInput || !beneficioSpan) return;

    function recalc() {
      var pvu = parseFloat(pvuInput.value.replace(',', '.')) || 0;
      var pcu = parseFloat(pcuInput.value.replace(',', '.')) || 0;
      var benef = pvu - pcu;
      beneficioSpan.textContent = benef.toFixed(2).replace('.', ',') + ' €';
    }

    pvuInput.addEventListener('input', recalc);
    pcuInput.addEventListener('input', recalc);
  }

  // Añadir listeners a filas existentes
  Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
    attachBenefitListeners(tr);
  });

  addBtn.addEventListener('click', function () {
    var key = 'new_' + (newIndex++);
    var tr = document.createElement('tr');
    tr.className = 'hover:bg-slate-50';

    var cells = '';

    // Producto
    cells += '<td class="min-w-[280px] py-2 pl-4 pr-2 align-middle">';
    cells += '<input type="text" name="productos[' + key + '][concepto]"';
    cells += ' class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"';
    cells += ' placeholder="Nuevo producto…">';
    cells += '</td>';

    // Uds.
    if (showUnidades) {
      cells += '<td class="py-2 pr-2 text-right align-middle">';
      cells += '<input type="number" step="0.01" min="0" name="productos[' + key + '][unidades]"';
      cells += ' class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"';
      cells += ' placeholder="0">';
      cells += '</td>';
    }

    // PMAXU
    if (showPmaxu) {
      cells += '<td class="py-2 pr-2 text-right align-middle">';
      cells += '<input type="number" step="0.01" min="0" name="productos[' + key + '][pmaxu]"';
      cells += ' class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"';
      cells += ' placeholder="0">';
      cells += '</td>';
    }

    // PVU
    cells += '<td class="py-2 pr-2 text-right align-middle">';
    cells += '<input type="number" step="0.01" min="0" name="productos[' + key + '][pvu]" data-role="pvu"';
    cells += ' class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"';
    cells += ' placeholder="0">';
    cells += '</td>';

    // PCU
    cells += '<td class="py-2 pr-2 text-right align-middle">';
    cells += '<input type="number" step="0.01" min="0" name="productos[' + key + '][pcu]" data-role="pcu"';
    cells += ' class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 text-right focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded"';
    cells += ' placeholder="0">';
    cells += '</td>';

    // Beneficio
    cells += '<td class="py-2 pr-2 text-right align-middle">';
    cells += '<span data-role="beneficio" class="text-xs text-slate-400">0,00 €</span>';
    cells += '</td>';

    tr.innerHTML = cells;
    tbody.appendChild(tr);

    attachBenefitListeners(tr);
  });
})();
</script>

