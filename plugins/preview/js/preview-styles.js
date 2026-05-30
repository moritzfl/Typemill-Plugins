const previewStyle = document.createElement('style');
previewStyle.textContent = `
.tm-preview-rendered{max-height:60vh;overflow:auto;padding:1.25rem;background:#f5f5f4;color:#1c1917}
.dark .tm-preview-rendered{background:#1c1917;color:#e7e5e4}
.tm-preview-rendered h1,.tm-preview-rendered h2,.tm-preview-rendered h3,.tm-preview-rendered h4,.tm-preview-rendered h5,.tm-preview-rendered h6{margin:0 0 .9rem;font-weight:700;line-height:1.2}
.tm-preview-rendered h1{font-size:2rem}
.tm-preview-rendered h2{font-size:1.6rem}
.tm-preview-rendered h3{font-size:1.3rem}
.tm-preview-rendered p,.tm-preview-rendered ul,.tm-preview-rendered ol,.tm-preview-rendered blockquote,.tm-preview-rendered pre,.tm-preview-rendered table{margin:0 0 1rem}
.tm-preview-rendered ul,.tm-preview-rendered ol{padding-left:1.5rem}
.tm-preview-rendered blockquote{padding-left:1rem;border-left:4px solid #14b8a6;opacity:.9}
.tm-preview-rendered a{color:#0f766e;text-decoration:underline}
.dark .tm-preview-rendered a{color:#5eead4}
.tm-preview-rendered code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.9em;background:rgba(120,113,108,.15);padding:.1rem .3rem;border-radius:.25rem}
.tm-preview-rendered pre{padding:1rem;overflow:auto;background:rgba(28,25,23,.08)}
.dark .tm-preview-rendered pre{background:rgba(245,245,244,.08)}
.tm-preview-rendered pre code{background:transparent;padding:0}
.tm-preview-rendered img,.tm-preview-rendered video,.tm-preview-rendered audio{max-width:100%}
.tm-preview-media{display:flex;align-items:center;justify-content:center;max-height:60vh;overflow:auto}
.tm-preview-media img,.tm-preview-media video,.tm-preview-media audio,.tm-preview-media embed,.tm-preview-media iframe{max-width:100%;max-height:60vh}
.tm-preview-folder-list table{border-collapse:collapse}
.tm-preview-folder-list th,.tm-preview-folder-list td{vertical-align:top}
.tm-preview-rendered table{width:100%;border-collapse:collapse}
.tm-preview-rendered th,.tm-preview-rendered td{padding:.5rem;border:1px solid rgba(120,113,108,.35)}
`;
document.head.appendChild(previewStyle);
