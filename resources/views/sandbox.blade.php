<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>MCP App sandbox</title>
</head>
<body>
<script nonce="{{ $nonce }}">
(() => {
    'use strict';

    const READY = 'ui/notifications/sandbox-proxy-ready';
    const RESOURCE = 'ui/notifications/sandbox-resource-ready';
    let view = null;

    const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
    const values = (value) => Array.isArray(value)
        ? value.filter((entry) => typeof entry === 'string')
        : [];
    const quote = (value) => value.replace(/[\r\n;]/g, '');

    function policy(csp) {
        const source = isRecord(csp) ? csp : {};
        const connect = values(source.connectDomains).map(quote);
        const resources = values(source.resourceDomains).map(quote);
        const frames = values(source.frameDomains).map(quote);
        const bases = values(source.baseUriDomains).map(quote);

        return [
            "default-src 'none'",
            "script-src 'unsafe-inline' " + resources.join(' '),
            "style-src 'unsafe-inline' " + resources.join(' '),
            "img-src data: blob: " + resources.join(' '),
            "font-src data: " + resources.join(' '),
            "media-src data: blob: " + resources.join(' '),
            "connect-src " + (connect.length ? connect.join(' ') : "'none'"),
            "frame-src " + (frames.length ? frames.join(' ') : "'none'"),
            "worker-src blob:",
            "object-src 'none'",
            "base-uri " + (bases.length ? bases.join(' ') : "'none'"),
            "form-action 'none'",
        ].join('; ');
    }

    function permissionPolicy(permissions) {
        const source = isRecord(permissions) ? permissions : {};
        const allowed = [];
        if (isRecord(source.camera)) allowed.push('camera');
        if (isRecord(source.microphone)) allowed.push('microphone');
        if (isRecord(source.geolocation)) allowed.push('geolocation');
        if (isRecord(source.clipboardWrite)) allowed.push('clipboard-write');
        return allowed.join('; ');
    }

    function injectCsp(html, csp) {
        const meta = '<meta http-equiv="Content-Security-Policy" content="'
            + policy(csp).replaceAll('&', '&amp;').replaceAll('"', '&quot;')
            + '">';
        const head = /<head(?:\s[^>]*)?>/i;
        return head.test(html) ? html.replace(head, (match) => match + meta) : meta + html;
    }

    function load(params) {
        if (!isRecord(params) || typeof params.html !== 'string') return;
        if (view !== null) view.remove();
        view = document.createElement('iframe');
        view.setAttribute('sandbox', 'allow-scripts');
        view.setAttribute('referrerpolicy', 'no-referrer');
        view.setAttribute('title', 'MCP App');
        const allow = permissionPolicy(params.permissions);
        if (allow) view.setAttribute('allow', allow);
        view.style.cssText = 'display:block;width:100%;height:100%;border:0;background:transparent';
        view.srcdoc = injectCsp(params.html, params.csp);
        document.body.replaceChildren(view);
    }

    window.addEventListener('message', (event) => {
        if (event.source === window.parent) {
            if (isRecord(event.data) && event.data.method === RESOURCE) {
                load(event.data.params);
                return;
            }
            if (view?.contentWindow) view.contentWindow.postMessage(event.data, '*');
            return;
        }
        if (view?.contentWindow && event.source === view.contentWindow) {
            if (isRecord(event.data) && String(event.data.method || '').startsWith('ui/notifications/sandbox-')) return;
            window.parent.postMessage(event.data, '*');
        }
    }, { passive: true });

    window.parent.postMessage({ jsonrpc: '2.0', method: READY, params: {} }, '*');
})();
</script>
</body>
</html>
