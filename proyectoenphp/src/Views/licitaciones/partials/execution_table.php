<?php

declare(strict_types=1);

/** @var array $licitacion */
$entregas = isset($licitacion['entregas']) && is_array($licitacion['entregas'])
    ? $licitacion['entregas']
    : [];

$idLicitacion = (int)($licitacion['id_licitacion'] ?? 0);

// Estados posibles de la línea de entrega (mismo texto que ESTADOS_LINEA_ENTREGA en React)
$estadosLinea = ['EN ESPERA', 'ENTREGADO', 'FACTURADO'];

?>

<form
  action="/licitaciones/<?php echo $idLicitacion; ?>/ejecucion"
  method="POST"
  class="mt-4"
>
  <div class="mb-3 flex items-center justify-between gap-2">
    <p class="text-sm text-slate-600">
      Resumen de entregas y albaranes vinculados a esta licitación.
    </p>
    <!-- En la versión React este botón abría un diálogo de nuevo albarán.
         Aquí mantenemos la presencia visual pero el alta se podrá hacer en otra pantalla/control. -->
    <button
      type="button"
      class="inline-flex items-center rounded-md bg-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
      disabled
    >
      ➕ Registrar Nuevo Albarán
    </button>
  </div>

  <?php if (empty($entregas)): ?>
    <p class="text-sm text-slate-500">
      No hay entregas registradas para esta licitación.
    </p>
  <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($entregas as $eIndex => $entrega): ?>
        <?php
          $idEntrega = (int)($entrega['id_entrega'] ?? 0);
          $codigoAlbaran = (string)($entrega['codigo_albaran'] ?? '');
          $fechaEntrega = (string)($entrega['fecha_entrega'] ?? '');
          $observaciones = (string)($entrega['observaciones'] ?? '');
          $lineas = isset($entrega['lineas']) && is_array($entrega['lineas']) ? $entrega['lineas'] : [];
        ?>
        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div class="flex flex-row items-center justify-between gap-3 px-4 py-3 border-b border-slate-100">
            <div>
              <h3 class="text-sm font-semibold text-slate-800">
                <?php echo htmlspecialchars($codigoAlbaran !== '' ? $codigoAlbaran : 'Sin código', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
              </h3>
              <p class="text-xs text-slate-500">
                Fecha:
                <input
                  type="date"
                  name="ejecucion[<?php echo $eIndex; ?>][fecha_entrega]"
                  value="<?php echo htmlspecialchars($fechaEntrega, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                  class="ml-1 inline-block h-6 rounded border border-slate-200 bg-white px-1 text-xs text-slate-700 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"
                />
              </p>
            </div>
            <div class="flex-1 text-right">
              <p class="mb-1 text-xs font-medium text-slate-600">Observaciones</p>
              <textarea
                name="ejecucion[<?php echo $eIndex; ?>][observaciones]"
                rows="2"
                class="w-full max-w-md rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-700 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"
              ><?php echo htmlspecialchars($observaciones, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
            </div>
          </div>

          <div class="px-4 py-3">
            <input
              type="hidden"
              name="ejecucion[<?php echo $eIndex; ?>][id_entrega]"
              value="<?php echo $idEntrega; ?>"
            />
            <input
              type="hidden"
              name="ejecucion[<?php echo $eIndex; ?>][codigo_albaran]"
              value="<?php echo htmlspecialchars($codigoAlbaran, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
            />

            <table class="min-w-full text-left text-sm">
              <thead>
                <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <th class="py-1.5 pr-3">Concepto</th>
                  <th class="py-1.5 pr-3">Proveedor</th>
                  <th class="py-1.5 pr-3 text-right">Cantidad</th>
                  <th class="py-1.5 pr-3 text-right">Coste</th>
                  <th class="py-1.5 pr-3 text-center">Estado</th>
                  <th class="py-1.5 pr-3 text-center">Cobrado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($lineas)): ?>
                  <tr>
                    <td colspan="6" class="py-4 text-center text-xs text-slate-500">
                      Sin líneas
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($lineas as $lIndex => $lin): ?>
                    <?php
                      $idReal = $lin['id_real'] ?? $lIndex;
                      $concepto = (string)($lin['product_nombre'] ?? '—');
                      $proveedor = (string)($lin['proveedor'] ?? '—');
                      $cantidad = (string)($lin['cantidad'] ?? '');
                      $pcu = (string)($lin['pcu'] ?? '');
                      $estadoActual = (string)($lin['estado'] ?? '');
                      $cobrado = isset($lin['cobrado']) ? (bool)$lin['cobrado'] : false;
                    ?>
                    <tr class="border-b border-slate-100 last:border-0">
                      <td class="py-1.5 pr-3 text-slate-900">
                        <span><?php echo htmlspecialchars($concepto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                        <input
                          type="hidden"
                          name="ejecucion[<?php echo $eIndex; ?>][lineas][<?php echo $lIndex; ?>][id_real]"
                          value="<?php echo htmlspecialchars((string)$idReal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                        />
                        <input
                          type="hidden"
                          name="ejecucion[<?php echo $eIndex; ?>][lineas][<?php echo $lIndex; ?>][product_nombre]"
                          value="<?php echo htmlspecialchars($concepto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                        />
                      </td>
                      <td class="py-1.5 pr-3 text-slate-600">
                        <span><?php echo htmlspecialchars($proveedor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                        <input
                          type="hidden"
                          name="ejecucion[<?php echo $eIndex; ?>][lineas][<?php echo $lIndex; ?>][proveedor]"
                          value="<?php echo htmlspecialchars($proveedor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                        />
                      </td>
                      <td class="py-1.5 pr-3 text-right text-slate-900">
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          name="ejecucion[<?php echo $eIndex; ?>][lineas][<?php echo $lIndex; ?>][cantidad]"
                          value="<?php echo htmlspecialchars($cantidad, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                          class="w-24 rounded border border-slate-200 bg-white px-2 py-0.5 text-right text-xs text-slate-900 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"
                        />
                      </td>
                      <td class="py-1.5 pr-3 text-right text-slate-900">
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          name="ejecucion[<?php echo $eIndex; ?>][lineas][<?php echo $lIndex; ?>][pcu]"
                          value="<?php echo htmlspecialchars($pcu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                          class="w-24 rounded border border-slate-200 bg-white px-2 py-0.5 text-right text-xs text-slate-900 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"
                        />
                      </td>
                      <td class="py-1.5 pr-3 text-center">
                        <select
                          name="ejecucion[<?php echo $eIndex; ?>][lineas][<?php echo $lIndex; ?>][estado]"
                          class="h-7 rounded border border-slate-200 bg-white px-2 text-xs text-slate-800 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"
                        >
                          <option value="">Estado…</option>
                          <?php foreach ($estadosLinea as $estado): ?>
                            <option
                              value="<?php echo htmlspecialchars($estado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                              <?php echo $estadoActual === $estado ? 'selected' : ''; ?>
                            >
                              <?php echo htmlspecialchars($estado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td class="py-1.5 pr-3 text-center">
                        <label class="inline-flex items-center gap-1 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            name="ejecucion[<?php echo $eIndex; ?>][lineas][<?php echo $lIndex; ?>][cobrado]"
                            value="1"
                            <?php echo $cobrado ? 'checked' : ''; ?>
                            class="h-3 w-3 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                          />
                          <span>Cobrado</span>
                        </label>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Zona para registrar rápidamente nuevas entregas / hitos sin recargar la página -->
  <div class="mt-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3">
    <div class="mb-2 flex items-center justify-between gap-2">
      <p class="text-xs font-medium text-slate-600">
        Nuevas entregas / hitos de ejecución
      </p>
      <button
        type="button"
        class="inline-flex items-center rounded-md bg-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 js-add-entrega"
      >
        ➕ Añadir Entrega
      </button>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-left text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <th class="py-1.5 pr-3">Concepto</th>
            <th class="py-1.5 pr-3">Proveedor</th>
            <th class="py-1.5 pr-3 text-right">Cantidad</th>
            <th class="py-1.5 pr-3 text-right">Coste</th>
            <th class="py-1.5 pr-3 text-center">Estado</th>
            <th class="py-1.5 pr-3 text-center">Cobrado</th>
          </tr>
        </thead>
        <tbody id="ejecucion-new-tbody">
          <!-- Filas dinámicas JS -->
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4 flex justify-end">
    <button
      type="submit"
      class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
    >
      Guardar Ejecución
    </button>
  </div>
</form>

<script>
(function () {
  var form = document.querySelector('form[action^="/licitaciones/"][action$="/ejecucion"]');
  if (!form) return;

  var tbody = document.getElementById('ejecucion-new-tbody');
  var addBtn = form.querySelector('.js-add-entrega');
  if (!tbody || !addBtn) return;

  // Contador incremental para claves "new_X"
  var newIndex = 1;

  function attachDeleteHandler(tr) {
    var deleteBtn = tr.querySelector('.js-remove-row');
    if (!deleteBtn) return;

    deleteBtn.addEventListener('click', function (ev) {
      ev.preventDefault();

      var existing = tr.getAttribute('data-existing') === '1';
      if (existing) {
        // Si en el futuro se añaden filas existentes con botón de borrar,
        // aquí se podría marcar un input hidden "deleted = 1" en lugar de
        // eliminar la fila directamente.
        var deletedInput = tr.querySelector('input[data-role="deleted-flag"]');
        if (!deletedInput) {
          deletedInput = document.createElement('input');
          deletedInput.type = 'hidden';
          deletedInput.setAttribute('data-role', 'deleted-flag');
          // El nombre concreto dependerá de cómo se serialicen las filas existentes.
          // Se deja preparado para que el backend pueda leerlo si se define.
          deletedInput.name = '';
          deletedInput.value = '1';
          tr.appendChild(deletedInput);
        }
        tr.style.opacity = '0.5';
      } else {
        // Fila nueva: simplemente la eliminamos del DOM
        tr.parentNode && tr.parentNode.removeChild(tr);
      }
    });
  }

  addBtn.addEventListener('click', function () {
    var key = 'new_' + (newIndex++);
    var tr = document.createElement('tr');
    tr.className = 'border-b border-slate-100 last:border-0 hover:bg-slate-50/50';

    var cells = '';

    // Concepto
    cells += '<td class="py-1.5 pr-3 text-slate-900">';
    cells += '<input type="text"';
    cells += ' name="ejecucion[' + key + '][concepto]"';
    cells += ' class="w-full rounded border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-900 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"';
    cells += ' placeholder="Concepto / producto">';
    cells += '</td>';

    // Proveedor
    cells += '<td class="py-1.5 pr-3 text-slate-600">';
    cells += '<input type="text"';
    cells += ' name="ejecucion[' + key + '][proveedor]"';
    cells += ' class="w-full rounded border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-900 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"';
    cells += ' placeholder="Proveedor">';
    cells += '</td>';

    // Cantidad
    cells += '<td class="py-1.5 pr-3 text-right text-slate-900">';
    cells += '<input type="number" step="0.01" min="0"';
    cells += ' name="ejecucion[' + key + '][cantidad]"';
    cells += ' class="w-24 rounded border border-slate-200 bg-white px-2 py-0.5 text-right text-xs text-slate-900 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"';
    cells += ' placeholder="0">';
    cells += '</td>';

    // Coste
    cells += '<td class="py-1.5 pr-3 text-right text-slate-900">';
    cells += '<input type="number" step="0.01" min="0"';
    cells += ' name="ejecucion[' + key + '][pcu]"';
    cells += ' class="w-24 rounded border border-slate-200 bg-white px-2 py-0.5 text-right text-xs text-slate-900 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500"';
    cells += ' placeholder="0,00">';
    cells += '</td>';

    // Estado
    cells += '<td class="py-1.5 pr-3 text-center">';
    cells += '<select';
    cells += ' name="ejecucion[' + key + '][estado]"';
    cells += ' class="h-7 rounded border border-slate-200 bg-white px-2 text-xs text-slate-800 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-emerald-500">';
    cells += '<option value="">Estado…</option>';
    cells += '<option value="EN ESPERA">EN ESPERA</option>';
    cells += '<option value="ENTREGADO">ENTREGADO</option>';
    cells += '<option value="FACTURADO">FACTURADO</option>';
    cells += '</select>';
    cells += '</td>';

    // Cobrado
    cells += '<td class="py-1.5 pr-3 text-center">';
    cells += '<label class="inline-flex items-center gap-1 text-xs text-slate-700">';
    cells += '<input type="checkbox"';
    cells += ' name="ejecucion[' + key + '][cobrado]"';
    cells += ' value="1"';
    cells += ' class="h-3 w-3 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">';
    cells += '<span>Cobrado</span>';
    cells += '</label>';
    cells += '</td>';

    tr.innerHTML = cells;
    tbody.appendChild(tr);

    attachDeleteHandler(tr);
  });
})();
</script>

