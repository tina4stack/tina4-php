# Frond Template Engine & Frontend

## Frond Overview

Frond is Tina4's native, zero-dependency, Twig-compatible template engine. Implemented from scratch
in each language with identical syntax. Architecture: Lexer -> Parser -> Compiler -> Runtime -> Cache.

## Syntax

### Output and Logic
```twig
{{ variable }}              {# Output with auto-escaping #}
{{ variable|raw }}          {# Unescaped output #}
{% if condition %}...{% endif %}  {# Logic blocks #}
{# This is a comment #}
```

### Variables and Access
```twig
{{ user.name }}             {# Dot notation #}
{{ items[0] }}              {# Array index #}
{{ data.users[0].name }}    {# Chained access #}
{{ value ?? "default" }}    {# Null coalescing #}
{{ user?.address?.city }}   {# Null-safe access #}
{{ "Hello " ~ name }}       {# String concatenation #}
{{ condition ? "yes" : "no" }}  {# Ternary #}
```

### Control Structures
```twig
{% if user.is_active %}
    Active
{% elseif user.is_pending %}
    Pending
{% else %}
    Inactive
{% endif %}

{% for user in users %}
    {{ loop.index }}. {{ user.name }}
{% else %}
    No users found.
{% endfor %}

{# Loop variables: loop.index, loop.index0, loop.first, loop.last,
   loop.length, loop.parent, loop.remaining, loop.depth #}

{% set greeting = "Hello " ~ name %}
```

The engine also implements these block tags: `{% spaceless %}...{% endspaceless %}`,
`{% autoescape false %}...{% endautoescape %}`, and `{% cache %}...{% endcache %}`.

> Frond does **not** implement `{% switch %}/{% case %}/{% default %}` or `{% capture %}`.
> Use `{% if %}/{% elseif %}` for branching.

**Both `{% set %}` forms work (block form FIXED in 3.13.89).** The inline
`{% set x = expr %}` assigns an expression; the block `{% set x %}...{% endset %}`
captures its rendered body.

```twig
{% set g %}Hello {{ n }}{% endset %}[{{ g }}]
{# renders "[Hello A]" #}
```

The captured value is marked SAFE (SafeString / raw marker), because it is template
output that was already escaped on the way in -- re-escaping at `{{ g }}` would
double-encode every entity. Twig and Jinja2 both mark a capture safe. Which form you get
is decided by ONE rule, identical in all four: an `=` anywhere in the tag content means
assignment, so `{% set m = "a = b" %}` is never mistaken for the block form.

Until 3.13.89 the block form printed its body inline and captured nothing (`"Hello A[]"`),
identically in all four -- a compatibility bug against both reference engines, not a
missing nicety.

**An UNKNOWN tag now raises (3.13.89, security-shaped).** A typo'd tag used to emit
nothing while its BODY was parsed as ordinary content, so
`{% iff user.is_admin %}<admin>{% endiff %}` rendered the gated block
**UNCONDITIONALLY** -- a reviewer read a guard that was not there. Twig and Jinja2 both
raise on an unknown tag; Frond now does too, naming the tag and listing the known ones.
There is no user-extension point for tags in any of the four frameworks, so an unknown
name is always a mistake, never a plugin. A STRAY TERMINATOR (an `{% endif %}` with no
`{% if %}`) is deliberately NOT covered: it stays a silent no-op, because it always was
one and it cannot expose gated content.

### Template Composition
```twig
{# Inheritance #}
{% extends "base.twig" %}
{% block content %}Page content{% endblock %}
{{ parent() }}  {# Include parent block content #}

{# Includes #}
{% include "header.twig" %}
{% include "sidebar.twig" with {"menu": items} %}
{% include "optional.twig" ignore missing %}

{# Macros #}
{% macro input(name, value, type) %}
    <input type="{{ type|default('text') }}" name="{{ name }}" value="{{ value }}">
{% endmacro %}
{% import "forms.twig" as forms %}
{{ forms.input("email", "", "email") }}

{# Also supported: {% from "forms.twig" import input %} #}
```

> Frond does **not** implement `{% embed %}`, `{% fragment %}`, or `{% push %}/{% stack %}`.
> For include-with-block-override, use `{% include ... with {...} %}` plus template
> inheritance (`{% extends %}` / `{% block %}`).

**DECIDED 2026-07-27, do not re-open: `{% fragment %}`, `{% push %}/{% stack %}` and
`{% switch %}` are DROPPED.** They are Laravel Blade idioms, not Twig or Jinja2 tags, so a
developer arriving from either engine Frond is modelled on will not reach for them. The
HTMX partial-render need that motivates Blade's `@fragment` is already served by Frond's
`{% live %}`, which is strictly more capable (server-rendered region plus poll/sse/ws
refresh). They appear in an old, stale local copy of this skill; that copy is wrong.

`{% embed %}` IS a real Twig tag (not Jinja2), so it is the only one with a genuine claim.
It stays unimplemented for now because it is an ERGONOMICS gap, not a capability gap:
`{% extends "card.twig" %}{% block title %}...{% endblock %}` was measured to produce
byte-identical output. Embed only adds inline, repeatable use mid-page. Revisit if a user
asks.

**Unknown block tags LEAK, they do not error.** Any unrecognised `{% foo %}...{% endfoo %}`
renders its body and swallows the tag:

```twig
{% frobnicate 42 %}INNER{% endfrobnicate %}   ->  "INNER"
{% iff user.is_admin %}<admin>{% endiff %}    ->  "<admin>"   (condition IGNORED)
```

That second one is the hazard: a typo in an `if` tag renders gated content
unconditionally. A fail-loud guard is scheduled for 3.13.88. Until then, never assume an
unrecognised tag is inert.

## The Cross-Framework Output Contract (3.13.87)

The same expression must render the same BYTES in Python, PHP, Ruby and Node. This was an
assumption until a 72-expression corpus was rendered through all four against one dataset:
**11 of 72 disagreed**. Each implementation looked correct in isolation, which is exactly
why the drift survived. The corpus is now a committed fixture -- `frond_expression_corpus.txt`
+ `frond_expression_expected.txt`, IDENTICAL BYTES in all four test dirs, one shared answer
key -- so a framework that drifts turns its own suite red.

Three rules that are now contract. Do not re-derive them per language:

1. **A boolean renders lowercase `true` / `false`.** Not Python's `True`/`False`, not
   Twig's `1`/`''`. A false value must never render BLANK -- that was PHP's bug, and a blank
   where a `false` belongs is invisible. Python needs BOTH output paths changed together
   (`frond/compiler.py::_tostr` is the live compiled path, `engine.Frond._to_output` the
   interpreted one); editing only the interpreter changes nothing AND the suite still passes.
2. **`{{ not x }}` works standalone**, not only inside `{% if %}`. Every logical operator is
   matched WITH surrounding spaces, so a LEADING `not` matches none of them and falls through
   to variable lookup as a variable named `"not x"`. Route it to the same evaluator `{% if %}`
   uses so a condition means one thing in both places.
3. **`|json_encode` emits JSON, not HTML entities.** Escape only `<` `>` `&` `'`
   (plus U+2028/U+2029) as JSON `\uXXXX` and mark the result safe -- the Jinja2
   `tojson` model, which is valid JSON AND valid JavaScript, cannot terminate a
   `</script>`, and is safe in a single-quoted attribute. Entity-encoding it (what
   3.13.87 did, in all four at once) is a SyntaxError inside `<script>`, the filter's
   main use. A value JSON cannot represent (Infinity, NaN) serializes as `null`; the
   filter NEVER returns empty and never a token that fails to parse. Slashes stay
   unescaped and non-ASCII stays raw, so all four agree byte for byte.

Both macro-import forms -- `{% import "f" as alias %}` and `{% from "f" import name %}` --
work and behave identically in all four. Do not bind the alias namespace as a class instance:
Python bound the macros as methods and silently shifted every argument by one.

**The bug class to watch for: the falsy guard.** Every boolean bug here was one -- `|| ""`,
`a[k] || a[k.to_sym]`, `true ? '1' : ''`. In a template engine "absent" and "false" are
DIFFERENT THINGS, and code that conflates them prints nothing where it should print
something. Probe with a key-existence check, never with truthiness.

## Frond-Unique Features ("Quirks")

### Live Blocks

Server-rendered regions that keep themselves fresh. `{% live "name" TRANSPORT %}...{% endlive %}`
renders on the server for first paint, then refreshes over the chosen transport: `poll N`
(every N seconds), `sse`, or `ws "path"`.

```twig
{% live "notifications" poll 5 %}
    {{ notifications|length }} new
{% endlive %}

{% live "chat-messages" ws "/ws/chat" %}
    {% for msg in messages %}{{ msg.text }}{% endfor %}
{% endlive %}
```

Data comes from a provider registered by name (`@live_source` / `Frond.liveSource` /
`Frond.live_source`), which re-runs with the live request on every refresh, so auth
re-applies. The provider feeds the always-on `GET /__frond/live/{name}` endpoint. For a `ws`
block, `push_live(name, data)` re-renders and broadcasts the fragment the instant data
changes. An `src "/path"` escape hatch points at a custom same-origin route; absolute URLs
and nested live blocks are rejected. The marker element is byte-identical across all four
frameworks, so the shared `frond.js` (poll + ws wired; sse client is v1.1) drives every
backend the same way.

### NOT implemented (do not use -- they render empty or wrong)

The engine does **not** implement these, despite their appearing in some older docs. Do not
emit them; there is no fallback and no warning:

- `{% query ... as ... %}` inline SQL -- run the query in the route/`@live_source` and pass
  the result into the template as a variable instead.
- `classes(...)` conditional-class helper -- build the class string with `{% if %}` / `~`.
- `{% markdown %}` -- render Markdown in the handler and pass HTML through `|raw`.
- `{% data ... as ... %}` JSON-script helper -- write the `<script type="application/json">`
  tag yourself with `|json_encode`. No `|raw` needed: since 3.13.88 the filter emits
  JSON that is already safe in a `<script>` block (see rule 3 above), and `|raw`
  after it is a no-op.
- `icon(...)` inline-SVG helper -- `{% include %}` the SVG partial.

## Filters (~59 total)

ALL filter names use **snake_case** inside templates, regardless of host language.

### String
`upper`, `lower`, `capitalize`, `title`, `trim`, `ltrim`, `rtrim`, `truncate`, `wordwrap`,
`pad`, `repeat`, `reverse`, `replace`, `split`, `slug`, `nl2br`, `striptags`, `urlize`,
`spaceless`, `indent`

### Array/Collection
`join`, `first`, `last`, `sort`, `sort_by`, `reverse`, `unique`, `where`, `find`, `reject`,
`group_by`, `map`, `pluck`, `flatten`, `batch`, `merge`, `slice`, `chunk`, `keys`, `values`,
`length`, `sum`, `min`, `max`, `average`, `column`

### Number
`number_format`, `abs`, `round`, `ceil`, `floor`, `at_least`, `at_most`, `filesizeformat`

### Date
`date`, `date_modify`, `timeago`

### Encoding/Security
`escape` (strategies: html, js, css, url), `raw`, `url_encode`, `url_decode`, `json_encode`,
`json_decode`, `base64_encode`, `base64_decode`

### Utility
`default`, `type`, `dump`

## Tests (in conditionals)
```twig
{% if value is defined %}
{% if list is empty %}
{% if value is null %}
{% if num is even %}
{% if num is odd %}
{% if items is iterable %}
{% if name is string %}
{% if count is number %}
{% if flag is boolean %}
{% if num is divisible by(3) %}
```

## Filter Registration API

The template-facing name is always snake_case. The registration API follows host language convention:

```python
# Python
frond.add_filter("my_filter", lambda value, arg: ...)

# PHP
$frond->addFilter("my_filter", function($value, $arg) { ... });

# Ruby
frond.add_filter("my_filter") { |value, arg| ... }

# Node.js
frond.addFilter("my_filter", (value, arg) => ...);
```

---

## frond.js Frontend Helper

Lightweight (<10KB minified), zero-dependency, framework-agnostic JavaScript library.
The unified frontend for all Tina4 backends. Supports ES Module + IIFE.

### HTTP Client
```javascript
const users = await Frond.get("/api/users", { params: { page: 1 } });
await Frond.post("/api/users", { name: "Alice" });
await Frond.put("/api/users/1", { name: "Alice Smith" });
await Frond.delete("/api/users/1");
```

### Form Handling
```javascript
Frond.submitForm("#user-form", "/api/users");
const data = Frond.formData("#user-form");
Frond.fillForm("#user-form", { name: "Alice", email: "alice@example.com" });
Frond.resetForm("#user-form");
```

### CRUD Table
```javascript
Frond.crud({
    target: "#users-table",
    endpoint: "/api/users",
    columns: ["id", "name", "email"],
    searchable: true,
    paginated: true
});
```

### UI Utilities
```javascript
Frond.modal({ title: "Confirm", body: "Are you sure?", onConfirm: () => {} });
Frond.confirm("Delete this item?").then(ok => {});
Frond.notify("Saved!", "success");  // success, error, warning, info

Frond.el("#my-id");         // querySelector
Frond.els(".my-class");     // querySelectorAll
Frond.on("#btn", "click", handler);
Frond.show("#panel"); Frond.hide("#panel"); Frond.toggle("#panel");
Frond.load("#container", "/api/partial");
```

### Authentication
```javascript
Frond.setToken(jwt);    // Stored in memory by default
Frond.getToken();
Frond.clearToken();
// All HTTP methods auto-attach Bearer token when Frond.config({ auth: true })
```

### WebSocket
```javascript
const ws = Frond.ws("/ws/chat", {
    reconnect: true,
    maxRetries: 10,
    heartbeat: true,
    onMessage: (data) => {},
    onOpen: () => {},
    onClose: () => {}
});
ws.send({ type: "message", text: "Hello" });
```

### Configuration
```javascript
Frond.config({
    baseUrl: "/api",
    auth: true,
    tokenStorage: "memory",  // or "localStorage"
    csrfToken: "...",
    defaultHeaders: { "X-Custom": "value" }
});
```
