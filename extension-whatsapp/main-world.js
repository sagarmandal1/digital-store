// main-world.js
// Runs in the PAGE's main world (not isolated content-script world).
// Can read window.Store, webpack modules, etc.
// Communicates with content.js via CustomEvents on window.

(function () {
    'use strict';

    function getActiveJid() {
        var jid = '';

        // ── Method 1: window.Store (classic WA Web) ──────────────────────────────
        try {
            if (window.Store && window.Store.Chat &&
                typeof window.Store.Chat.getActive === 'function') {
                var chat = window.Store.Chat.getActive();
                if (chat && chat.id && chat.id._serialized) {
                    jid = chat.id._serialized;
                }
            }
        } catch (e) { /* ignore */ }

        if (jid) return jid;

        // ── Method 2: webpack chunk modules (modern WA Web) ─────────────────────
        try {
            var chunkKey = '';
            var wKeys = Object.keys(window);
            for (var i = 0; i < wKeys.length; i++) {
                if (wKeys[i].indexOf('webpackChunk') === 0) {
                    chunkKey = wKeys[i];
                    break;
                }
            }

            if (chunkKey && Array.isArray(window[chunkKey])) {
                // Collect all module factories from all chunks
                var factories = {};
                window[chunkKey].forEach(function (entry) {
                    if (entry && entry[1] && typeof entry[1] === 'object') {
                        var ids = Object.keys(entry[1]);
                        for (var j = 0; j < ids.length; j++) {
                            factories[ids[j]] = entry[1][ids[j]];
                        }
                    }
                });

                var fKeys = Object.keys(factories);
                for (var fi = 0; fi < fKeys.length && !jid; fi++) {
                    try {
                        var factory = factories[fKeys[fi]];
                        if (typeof factory !== 'function') continue;

                        // Execute factory with minimal stub
                        var mod = { exports: {} };
                        factory(mod, mod.exports, function () { return {}; });

                        var exp = mod.exports;
                        if (!exp || typeof exp !== 'object') continue;

                        var expKeys = Object.keys(exp);
                        for (var ei = 0; ei < expKeys.length && !jid; ei++) {
                            var ex = exp[expKeys[ei]];
                            if (ex && typeof ex.getActive === 'function') {
                                var active = ex.getActive();
                                if (active && active.id && active.id._serialized) {
                                    jid = active.id._serialized;
                                }
                            }
                        }
                    } catch (fe) { /* skip broken modules */ }
                }
            }
        } catch (e) { /* ignore */ }

        if (jid) return jid;

        // ── Method 3: window.require / __wajs_require ────────────────────────────
        try {
            var req = window.require || window.__wajs_require;
            if (typeof req === 'function') {
                var modIds = ['ChatStore', 'ChatCollection', 'chat_store'];
                for (var mi = 0; mi < modIds.length && !jid; mi++) {
                    try {
                        var m = req(modIds[mi]);
                        if (m && typeof m.getActive === 'function') {
                            var c = m.getActive();
                            if (c && c.id && c.id._serialized) {
                                jid = c.id._serialized;
                            }
                        }
                    } catch (re) { /* skip */ }
                }
            }
        } catch (e) { /* ignore */ }

        return jid;
    }

    // Listen for requests from the isolated content script
    window.addEventListener('__ds_req_jid', function (e) {
        var reqId = (e && e.detail && e.detail.reqId) ? e.detail.reqId : '';
        var jid = '';
        try { jid = getActiveJid(); } catch (err) { /* ignore */ }
        window.dispatchEvent(new CustomEvent('__ds_res_jid', {
            detail: { jid: jid, reqId: reqId }
        }));
    });
})();