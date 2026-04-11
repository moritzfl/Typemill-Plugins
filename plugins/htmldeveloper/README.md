# HTML Developer Mode — Typemill Plugin

A Typemill plugin that lets you embed raw HTML, CSS, and JavaScript directly in your content.

## Installation

See [Installation in the project README](../../README.md#installation).

## Usage

### Fenced code blocks

Wrap any HTML, CSS, or JavaScript in a fenced code block tagged `rawhtml`. Typemill will render it as real HTML instead
of a code listing.

~~~markdown
```rawhtml
<div class="my-banner">
  <h2>Hello from raw HTML</h2>
  <p>This is rendered directly in the page.</p>
</div>

<style>
  .my-banner {
    background: #1a1a2e;
    color: white;
    padding: 2rem;
    border-radius: 8px;
  }
</style>

<script>
  document.querySelector('.my-banner').addEventListener('click', function () {
    alert('You clicked the banner!');
  });
</script>
```
~~~

You can mix HTML, `<style>`, and `<script>` tags freely inside a single block. Each block is self-contained — Typemill
passes the contents straight to the browser without escaping anything.

### External resources (CSP)

If your embedded HTML loads resources from external domains (images, scripts, fonts, iframes), you need to whitelist
those domains in the plugin settings under **Allowed External Domains**. One domain per line, or comma-separated.

To allow all external HTTPS resources at once, add `https:` as a domain. This is the easiest option during development.

```
https:
```

For a tighter policy, list specific origins instead:

```
https://img.shields.io
https://cdn.jsdelivr.net
https://fonts.googleapis.com
```

## Examples

### Custom component

~~~markdown
```rawhtml
<div class="card">
  <img src="/media/photo.jpg" alt="My photo">
  <div class="card-body">
    <h3>Card title</h3>
    <p>Some description text.</p>
  </div>
</div>

<style>
  .card { border: 1px solid #ddd; border-radius: 4px; overflow: hidden; max-width: 320px; }
  .card img { width: 100%; display: block; }
  .card-body { padding: 1rem; }
</style>
```
~~~

### Embedded iframe

~~~markdown
```rawhtml
<iframe
  width="560"
  height="315"
  src="https://www.youtube.com/embed/dQw4w9WgXcQ"
  frameborder="0"
  allowfullscreen>
</iframe>
```
~~~

### Interactive widget

~~~markdown
```rawhtml
<div id="counter">
  <button id="btn">Clicks: <span id="count">0</span></button>
</div>

<script>
  (function () {
    var count = 0;
    document.getElementById('btn').addEventListener('click', function () {
      document.getElementById('count').textContent = ++count;
    });
  })();
</script>
```
~~~

### Custom CSS for the whole page

~~~markdown
```rawhtml
<style>
  :root {
    --accent: #e63946;
  }
  h1, h2, h3 {
    color: var(--accent);
  }
</style>
```
~~~

## Notes

- Multiple `rawhtml` blocks per page are supported.
- Regular markdown (headings, lists, links, etc.) works as normal alongside `rawhtml` blocks.
- The plugin only affects frontend rendering — it has no impact on the Typemill editor.
- External resources (images, scripts, fonts, iframes) require their domains to be whitelisted in the plugin settings.
