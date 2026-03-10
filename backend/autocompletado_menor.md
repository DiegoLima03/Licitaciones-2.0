# Guía de Autocompletado Multi‑palabra (Tokenizado) en Verabuy

## Objetivo
Esta guía explica paso a paso cómo crear un **autocompletado** que permita buscar por varias palabras en cualquier orden (por ejemplo, "clavel 25u" o "25u clavel").  El código está basado en la implementación existente en `comprador/ordenes.php` y se puede reutilizar en cualquier otro input del proyecto.

---

## 1. Arquitectura general

1. **Backend (PHP)** – Funciones que reciben la query, la tokenizan y generan una consulta SQL con **AND** entre los tokens.  Devuelven un JSON con los items para el menú.
2. **Frontend (JavaScript)** – Clase `AutoComplete` que llama al endpoint `?ajax=autocomplete&entity=...&q=...` y muestra los resultados resaltando los tokens.
3. **HTML** – Input + campos ocultos + contenedores para el menú y el estado.

---

## 2. Backend: tokenizar la búsqueda

### 2.1 Función genérica de tokenización
```php
/**
 * Convierte la cadena de búsqueda en tokens (palabras de al menos 2 caracteres).
 */
function tokenize(string $query): array {
    $q = lowerUtf8(norm($query));
    $tokens = array_values(array_filter(
        preg_split('/\s+/', $q) ?: [],
        fn($t) => mb_strlen($t, 'UTF-8') >= 2
    ));
    return $tokens ?: [$q]; // si no hay tokens, usar la query completa
}
```
Esta función se usa en **searchContactos**, **searchProductores** y **searchArticulos**.

### 2.2 Ejemplo: `searchContactos`
```php
function searchContactos(PDO $pdo, string $query, int $limit = 18, $tipoContacto = null): array {
    $limit = max(5, min(25, $limit));
    $tokens = tokenize($query);

    $nombreWheres = [];
    $fiscalWheres = [];
    $nombreScores = [];
    $params = [];

    foreach ($tokens as $i => $tok) {
        $eTok = escLike($tok);
        // WHEREs
        $nombreWheres[] = "LOWER(COALESCE(nombre, '')) LIKE :tw_n_{$i} ESCAPE '\\\\'";
        $fiscalWheres[] = "LOWER(COALESCE(nombre_fiscal, '')) LIKE :tw_f_{$i} ESCAPE '\\\\'";
        $params[":tw_n_{$i}"] = "%{$eTok}%";
        $params[":tw_f_{$i}"] = "%{$eTok}%";
        // SCORE (mantiene la lógica original de coincidencia exacta, prefijo, palabra, contiene)
        $nombreScores[] = "CASE
            WHEN LOWER(COALESCE(nombre, '')) = :teq_{$i} THEN 220
            WHEN LOWER(COALESCE(nombre, '')) LIKE :tpfx_{$i} ESCAPE '\\\\' THEN 190
            WHEN LOWER(COALESCE(nombre, '')) LIKE :twrd_{$i} ESCAPE '\\\\' THEN 165
            WHEN LOWER(COALESCE(nombre, '')) LIKE :tcon_{$i} ESCAPE '\\\\' THEN 130
            ELSE 0
        END";
        $params[":teq_{$i}"]  = $tok;
        $params[":tpfx_{$i}"] = $eTok . '%';
        $params[":twrd_{$i}"] = "% {$eTok}%";
        $params[":tcon_{$i}"] = "%{$eTok}%";
    }

    $nombreWhereStr = implode("\n        AND ", $nombreWheres);
    $fiscalWhereStr = implode("\n        AND ", $fiscalWheres);
    $nombreScoreStr = implode("\n        + ", $nombreScores);

    // Parámetros de búsqueda por ID ERP (se usan con la query completa)
    $q   = lowerUtf8(norm($query));
    $esc = escLike($q);
    $num = preg_replace('/\D+/', '', $q);
    $params[':id_exact']           = $num;
    $params[':id_like']            = '%' . escLike($num) . '%';
    $params[':contains_fiscal_sc'] = "%{$esc}%";
    $params[':id_like_where']      = "%{$esc}%";

    // (Opcional) filtro por tipo de contacto …
    // … (código omitido para brevedad)

    $sql = "
        SELECT
            id, id_erp, nombre, nombre_fiscal, tipo_contacto, bloqueado,
            ({$nombreScoreStr}
                + CASE WHEN LOWER(COALESCE(nombre_fiscal, '')) LIKE :contains_fiscal_sc ESCAPE '\\\\' THEN 65 ELSE 0 END
                + CASE WHEN CAST(id_erp AS CHAR) = :id_exact THEN 140
                       WHEN CAST(id_erp AS CHAR) LIKE :id_like ESCAPE '\\\\' THEN 40 ELSE 0 END
                + CASE WHEN bloqueado = 1 THEN -30 ELSE 0 END) AS score
        FROM contactos
        WHERE (
            ({$nombreWhereStr})
            OR ({$fiscalWhereStr})
            OR CAST(id_erp AS CHAR) LIKE :id_like_where ESCAPE '\\\\'
        )
        {$typeFilterSql}
        ORDER BY score DESC, bloqueado ASC, nombre ASC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    // … construir el array de items (igual que antes) …
}
```
> **Resultado:** la consulta solo devuelve contactos cuyo `nombre` **y** `nombre_fiscal` contienen *todos* los tokens, sin importar el orden.

### 2.3 `searchProductores` (muy similar)
```php
function searchProductores(PDO $pdo, string $query, int $limit = 18): array {
    $limit = max(5, min(25, $limit));
    $tokens = tokenize($query);
    $nombreWheres = [];
    $nombreScores = [];
    $params = [];
    foreach ($tokens as $i => $tok) {
        $eTok = escLike($tok);
        $nombreWheres[] = "LOWER(COALESCE(nombre, '')) LIKE :tw_{$i} ESCAPE '\\\\'";
        $params[":tw_{$i}"] = "%{$eTok}%";
        $nombreScores[] = "CASE
            WHEN LOWER(COALESCE(nombre, '')) = :teq_{$i} THEN 220
            WHEN LOWER(COALESCE(nombre, '')) LIKE :tpfx_{$i} ESCAPE '\\\\' THEN 190
            WHEN LOWER(COALESCE(nombre, '')) LIKE :twrd_{$i} ESCAPE '\\\\' THEN 165
            WHEN LOWER(COALESCE(nombre, '')) LIKE :tcon_{$i} ESCAPE '\\\\' THEN 130
            ELSE 0
        END";
        $params[":teq_{$i}"]  = $tok;
        $params[":tpfx_{$i}"] = $eTok . '%';
        $params[":twrd_{$i}"] = "% {$eTok}%";
        $params[":tcon_{$i}"] = "%{$eTok}%";
    }
    $nombreWhereStr = implode("\n      AND ", $nombreWheres);
    $nombreScoreStr = implode("\n        + ", $nombreScores);
    $num = preg_replace('/\D+/', '', lowerUtf8(norm($query)));
    $params[':id_exact'] = $num;
    $params[':id_like']  = '%' . escLike($num) . '%';
    $params[':id_like_where'] = '%' . escLike($num) . '%';

    $sql = "
        SELECT
            id, id_erp, nombre,
            ({$nombreScoreStr}
                + CASE WHEN CAST(id_erp AS CHAR) = :id_exact THEN 120
                       WHEN CAST(id_erp AS CHAR) LIKE :id_like ESCAPE '\\\\' THEN 40 ELSE 0 END) AS score
        FROM productores
        WHERE ({$nombreWhereStr})
            OR CAST(id_erp AS CHAR) LIKE :id_like_where ESCAPE '\\\\'
        ORDER BY score DESC, nombre ASC
        LIMIT {$limit}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    // … construir items …
}
```
---

## 3. Frontend: la clase `AutoComplete`

### 3.1 Código esencial (simplificado)
```js
class AutoComplete {
  constructor({entity, input, hidden, menu, status, limit = 18, onSelected, onCleared}) {
    this.entity = entity;               // 'cliente', 'proveedor', 'articulo', etc.
    this.input  = input;                // <input id="line_cliente_search">
    this.hidden = hidden;               // <input type="hidden" id="line_id_cliente">
    this.menu   = menu;                 // <div class="ac-menu" id="line_cliente_menu" hidden>
    this.status = status;               // <div class="ac-status" id="line_cliente_status">
    this.limit  = Math.max(5, Math.min(120, Number(limit)));
    this.cache  = new Map();
    this.abort  = null;
    this.items  = [];
    this.active = -1;
    this.query  = '';
    this.bind();
  }

  bind() {
    this.input.addEventListener('input', () => this.onInput());
    this.input.addEventListener('keydown', e => this.onKey(e));
    this.input.addEventListener('blur', () => setTimeout(() => this.close(), 120));
    document.addEventListener('click', e => {
      if (!this.menu.contains(e.target) && e.target !== this.input) this.close();
    });
  }

  onInput() {
    const value = this.input.value.trim();
    this.query = value;
    if (value.length < 4) { // mismo umbral que el backend
      this.clear(); this.close(); this.setStatus('Escribe al menos 4 caracteres');
      return;
    }
    this.setStatus('Buscando…');
    this.fetchDebounced(value);
  }

  async fetch(value) {
    const key = value.toLowerCase();
    if (this.cache.has(key)) { this.render(this.cache.get(key), value); return; }
    if (this.abort) this.abort.abort();
    this.abort = new AbortController();
    const url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax', 'autocomplete');
    url.searchParams.set('entity', this.entity);
    url.searchParams.set('q', value);
    url.searchParams.set('limit', this.limit);
    try {
      const res = await fetch(url, {signal: this.abort.signal, headers:{Accept:'application/json'}});
      if (!res.ok) throw new Error('HTTP '+res.status);
      const payload = await res.json();
      const items = Array.isArray(payload.items) ? payload.items : [];
      this.cache.set(key, items);
      if (this.query.toLowerCase() !== key) return; // stale response
      this.render(items, value);
    } catch (e) {
      if (e.name === 'AbortError') return;
      this.clear(); this.close(); this.setStatus('No se pudieron cargar sugerencias', true);
    }
  }

  render(items, query) {
    this.clear();
    if (!items.length) { this.setStatus(`Sin resultados para "${query}"`); this.close(); return; }
    const frag = document.createDocumentFragment();
    items.forEach((item,i)=>{
      const btn = document.createElement('button');
      btn.type='button';
      btn.className='ac-option';
      btn.innerHTML = `<span class="ac-label">${highlight(item.label, query)}</span>`+
                     `<span class="ac-meta">${highlight(item.meta, query)}</span>`;
      btn.addEventListener('mouseenter',()=>{this.active=i; this.paintActive();});
      btn.addEventListener('mousedown',e=>e.preventDefault());
      btn.addEventListener('click',()=>this.choose(i));
      frag.appendChild(btn);
    });
    this.items = items;
    this.menu.appendChild(frag);
    this.menu.hidden = false;
    this.active = 0; this.paintActive();
    const reachedCap = items.length >= this.limit;
    const status = reachedCap ? `${items.length} resultados (tope alcanzado)` : `${items.length} resultados`;
    this.setStatus(status);
  }

  // … métodos clear, close, paintActive, choose, setStatus, onKey … (igual que en el código original)
}
```
### 3.2 Cómo se instancia
```js
new AutoComplete({
  entity: 'cliente',
  input: document.getElementById('line_cliente_search'),
  hidden: document.getElementById('line_id_cliente'),
  menu: document.getElementById('line_cliente_menu'),
  status: document.getElementById('line_cliente_status')
});
```
Repite la instancia cambiando `entity` y los IDs para **proveedor**, **articulo**, **productor**, etc.

---

## 4. HTML necesario
```html
<label class="detail-field">
  <span class="detail-field-label">Cliente</span>
  <input id="line_cliente_search" type="text" autocomplete="off" placeholder="Buscar cliente…">
  <input id="line_id_cliente" type="hidden">
  <div id="line_cliente_menu" class="ac-menu" role="listbox" hidden></div>
  <div id="line_cliente_status" class="ac-status"></div>
</label>
```
- **`ac-menu`**: contenedor de los `<button class="ac-option">` que se generan.
- **`ac-status`**: muestra mensajes como *"Buscando…"* o *"Sin resultados"*.
- El **campo oculto** (`hidden`) guarda el `id` del elemento seleccionado para enviarlo al servidor.

---

## 5. Paso a paso para añadir un nuevo campo de autocompletado
1. **Crear los elementos HTML** (input, hidden, menu, status) siguiendo el ejemplo anterior.
2. **Agregar la instancia JavaScript** con el `entity` correcto (el mismo que el backend espera).
3. **Implementar la función PHP** que atiende la entidad (`searchContactos`, `searchProductores`, `searchArticulos`). Copia la lógica de tokenización mostrada.
4. **Probar** escribiendo palabras en distinto orden; el menú debe mostrar coincidencias que contengan **todos** los tokens.
5. (Opcional) **Ajustar el umbral** cambiando `if (value.length < 4)` en `onInput` y el mismo valor en `ordenes.php` (`lenUtf8($query) < 4`).

---

## 6. Resumen de los conceptos clave
| Concepto | Descripción |
|---|---|
| **Tokenización** | Dividir la cadena de búsqueda en palabras (tokens) y usar `AND` entre ellas. |
| **LIKE con comodines** | Cada token se busca con `%token%` (contiene) y también con variantes para puntuación exacta, prefijo, palabra completa, etc. |
| **Cache del cliente** | Evita peticiones repetidas para la misma query usando `Map` en JavaScript. |
| **AbortController** | Cancela peticiones en curso cuando el usuario escribe rápidamente. |
| **Resaltar coincidencias** | La función `highlight` envuelve en `<span class="ac‑mark">` los tokens que aparecen en la etiqueta o meta. |

---

## 7. Preguntas frecuentes (FAQ)
- **¿Puedo cambiar el número mínimo de caracteres?** Sí, modifica `if (value.length < 4)` en `AutoComplete.onInput` y el mismo chequeo en `ordenes.php` (`lenUtf8($query) < 4`).
- **¿Cómo añado un nuevo campo que busque por código interno?** Añade la columna a la cláusula `WHERE` y al `SELECT` en la función PHP correspondiente, siguiendo el patrón de los tokens.
- **¿Qué pasa si la query contiene caracteres especiales?** `escLike()` escapa `%`, `_` y `\` antes de usarlos en `LIKE`.
- **¿Se pueden buscar números y letras juntos?** Sí, la tokenización no discrimina; cualquier secuencia de caracteres se trata como token.

---

## 8. Código completo (para referencia)
Los archivos completos se encuentran en el proyecto:
- **Backend:** `c:/wamp64/www/verabuy/comprador/ordenes.php` (funciones `searchContactos`, `searchProductores`, `searchArticulos`).
- **Frontend:** Dentro del mismo archivo, en la sección `<script>` a partir de la línea 2562 (clase `AutoComplete`).
- **HTML:** En la plantilla del formulario de línea de orden (líneas 2235‑2246).

Con esta guía cualquier desarrollador podrá replicar o adaptar el autocompletado multi‑palabra en otras partes de la aplicación.
