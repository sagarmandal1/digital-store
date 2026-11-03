// main-world.js
// Runs in the PAGE's main world (not isolated content-script world).
// Can read window.Store, webpack modules, etc.
// Communicates with content.js via CustomEvents on window.

(function () {
    'use strict';

    // ── Helper: Extract React Fiber ──────────────────────────────────────────
    function getReactFiber(el) {
        if (!el) return null;
        var keys = Object.keys(el);
        for (var i = 0; i < keys.length; i++) {
            if (keys[i].indexOf('__reactFiber$') === 0 || keys[i].indexOf('__reactInternalInstance$') === 0) {
                return el[keys[i]];
            }
        }
        return null;
    }

    // ── Method 1: React Fiber Deep Inspection (modern WhatsApp Web) ──────────
    function extractFromFiber() {
        var targets = [
            document.querySelector('#main header'),
            document.querySelector('[data-testid="conversation-header"]'),
            document.querySelector('#pane-side [aria-selected="true"]'),
            document.querySelector('#main')
        ];

        for (var t = 0; t < targets.length; t++) {
            var el = targets[t];
            if (!el) continue;
            var fiber = getReactFiber(el);
            if (!fiber) continue;

            var curr = fiber;
            var depth = 0;
            while (curr && depth < 40) {
                var p = curr.memoizedProps;
                if (p && typeof p === 'object') {
                    var chat = p.chat || (p.conversation && p.conversation.chat) || p.activeChat;
                    var contact = p.contact || (chat && chat.contact);

                    if (!chat && !contact && p.channel && p.channel.contact) {
                        contact = p.channel.contact;
                        chat = p.channel;
                    }

                    if (!chat && !contact && p.children && p.children.props) {
                        chat = p.children.props.chat || p.children.props.activeChat;
                        contact = p.children.props.contact || (chat && chat.contact);
                    }

                    var target = contact || chat;
                    if (target && target.id) {
                        var rawJid = target.id._serialized || (typeof target.id === 'string' ? target.id : '');
                        if (rawJid && (rawJid.indexOf('@c.us') !== -1 || rawJid.indexOf('@g.us') !== -1 || rawJid.indexOf('@s.whatsapp.net') !== -1)) {
                            var userPhone = target.id.user ? String(target.id.user) : (rawJid.split('@')[0] || '');
                            var pushname = (contact && contact.pushname) ? contact.pushname : (target.pushname || '');
                            var name = (contact && contact.name) ? contact.name : (target.name || '');
                            var formattedTitle = target.formattedTitle || (chat && chat.formattedTitle) || '';
                            var isBusiness = !!((contact && (contact.isBusiness || contact.isEnterprise)) || target.isBusiness || target.isEnterprise);
                            var avatarUrl = '';
                            var pObj = (contact && contact.profilePicThumbObj) || target.profilePicThumbObj;
                            if (pObj) {
                                avatarUrl = pObj.eurl || pObj.img || '';
                            }
                            var about = (contact && contact.about) || target.about || '';
                            var isGroup = rawJid.indexOf('@g.us') !== -1 || !!target.isGroup;

                            return {
                                jid: rawJid,
                                phone: userPhone,
                                name: name,
                                pushname: pushname,
                                formattedTitle: formattedTitle,
                                isBusiness: isBusiness,
                                avatarUrl: avatarUrl,
                                about: about,
                                isGroup: isGroup
                            };
                        }
                    }
                }
                curr = curr.return;
                depth++;
            }
        }
        return null;
    }

    // ── Method 2: window.Store & Webpack (Classic & Modern WA Web) ────────────
    function extractFromStore() {
        // 2a. window.Store
        try {
            if (window.Store && window.Store.Chat && typeof window.Store.Chat.getActive === 'function') {
                var chat = window.Store.Chat.getActive();
                if (chat && chat.id && chat.id._serialized) {
                    var jid = chat.id._serialized;
                    var contact = chat.contact || (window.Store.Contact && typeof window.Store.Contact.get === 'function' && window.Store.Contact.get(chat.id));
                    var pObj = (contact && contact.profilePicThumbObj) || chat.profilePicThumbObj;
                    return {
                        jid: jid,
                        phone: chat.id.user ? String(chat.id.user) : (jid.split('@')[0] || ''),
                        name: (contact && contact.name) || chat.name || '',
                        pushname: (contact && contact.pushname) || '',
                        formattedTitle: chat.formattedTitle || '',
                        isBusiness: !!(contact && (contact.isBusiness || contact.isEnterprise)),
                        avatarUrl: (pObj && (pObj.eurl || pObj.img)) || '',
                        about: (contact && contact.about) || '',
                        isGroup: jid.indexOf('@g.us') !== -1 || !!chat.isGroup
                    };
                }
            }
        } catch (e) { /* ignore */ }

        // 2b. Webpack chunk modules
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
                for (var fi = 0; fi < fKeys.length; fi++) {
                    try {
                        var factory = factories[fKeys[fi]];
                        if (typeof factory !== 'function') continue;
                        var mod = { exports: {} };
                        factory(mod, mod.exports, function () { return {}; });
                        var exp = mod.exports;
                        if (!exp || typeof exp !== 'object') continue;

                        var expKeys = Object.keys(exp);
                        for (var ei = 0; ei < expKeys.length; ei++) {
                            var ex = exp[expKeys[ei]];
                            if (ex && typeof ex.getActive === 'function') {
                                var active = ex.getActive();
                                if (active && active.id && active.id._serialized) {
                                    var ajid = active.id._serialized;
                                    var c = active.contact || null;
                                    var pic = (c && c.profilePicThumbObj) || active.profilePicThumbObj;
                                    return {
                                        jid: ajid,
                                        phone: active.id.user ? String(active.id.user) : (ajid.split('@')[0] || ''),
                                        name: (c && c.name) || active.name || '',
                                        pushname: (c && c.pushname) || '',
                                        formattedTitle: active.formattedTitle || '',
                                        isBusiness: !!(c && (c.isBusiness || c.isEnterprise)),
                                        avatarUrl: (pic && (pic.eurl || pic.img)) || '',
                                        about: (c && c.about) || '',
                                        isGroup: ajid.indexOf('@g.us') !== -1 || !!active.isGroup
                                    };
                                }
                            }
                        }
                    } catch (fe) { /* skip */ }
                }
            }
        } catch (e) { /* ignore */ }

        // 2c. window.require
        try {
            var req = window.require || window.__wajs_require;
            if (typeof req === 'function') {
                var modIds = ['ChatStore', 'ChatCollection', 'chat_store'];
                for (var mi = 0; mi < modIds.length; mi++) {
                    try {
                        var m = req(modIds[mi]);
                        if (m && typeof m.getActive === 'function') {
                            var act = m.getActive();
                            if (act && act.id && act.id._serialized) {
                                return {
                                    jid: act.id._serialized,
                                    phone: act.id.user ? String(act.id.user) : (act.id._serialized.split('@')[0] || ''),
                                    name: act.name || '',
                                    pushname: (act.contact && act.contact.pushname) || '',
                                    formattedTitle: act.formattedTitle || '',
                                    isBusiness: !!(act.contact && act.contact.isBusiness),
                                    avatarUrl: '',
                                    about: '',
                                    isGroup: act.id._serialized.indexOf('@g.us') !== -1
                                };
                            }
                        }
                    } catch (re) { /* skip */ }
                }
            }
        } catch (e) { /* ignore */ }

        return null;
    }

    // ── Method 3: DOM Fallback for Avatar, Pushname & Business ─────────────────
    function extractFromDom() {
        var h = document.querySelector('#main header') || document.querySelector('[data-testid="conversation-header"]');
        if (!h) return null;

        var avatarUrl = '';
        var img = h.querySelector('img[src*="blob:"], img[src*="pps.whatsapp.net"], img');
        if (img && img.src && img.src.indexOf('data:image/svg') === -1) {
            avatarUrl = img.src;
        }

        var pushname = '';
        var subSpan = h.querySelector('[data-testid="conversation-info-header"] span:not([title])') ||
                      h.querySelector('div[role="heading"] + span') ||
                      h.querySelector('header span[dir="auto"]');
        if (subSpan) {
            var m = (subSpan.textContent || '').trim().match(/^~([^\n\r]+)$/);
            if (m) pushname = m[1].trim();
        }

        var isBusiness = !!h.querySelector('[data-icon="business-verified"], [data-icon="business"], [data-testid="business-chat"]');

        return {
            avatarUrl: avatarUrl,
            pushname: pushname,
            isBusiness: isBusiness
        };
    }

    // ── Master Contact Details Resolver ───────────────────────────────────────
    function getActiveContactDetails() {
        // Try Fiber first (most accurate and modern)
        var fiberData = null;
        try { fiberData = extractFromFiber(); } catch (e) { /* ignore */ }

        // Try Store
        var storeData = null;
        try { storeData = extractFromStore(); } catch (e) { /* ignore */ }

        // DOM Fallback
        var domData = null;
        try { domData = extractFromDom(); } catch (e) { /* ignore */ }

        var base = fiberData || storeData || {};
        var dom = domData || {};

        return {
            jid: base.jid || '',
            phone: base.phone || '',
            name: base.name || '',
            pushname: base.pushname || dom.pushname || '',
            formattedTitle: base.formattedTitle || '',
            isBusiness: !!(base.isBusiness || dom.isBusiness),
            avatarUrl: base.avatarUrl || dom.avatarUrl || '',
            about: base.about || '',
            isGroup: !!base.isGroup
        };
    }

    function getActiveJid() {
        var info = getActiveContactDetails();
        return info.jid || '';
    }

    // Listen for requests from the isolated content script
    window.addEventListener('__ds_req_jid', function (e) {
        var reqId = (e && e.detail && e.detail.reqId) ? e.detail.reqId : '';
        var info = { jid: '' };
        try { info = getActiveContactDetails(); } catch (err) { /* ignore */ }
        window.dispatchEvent(new CustomEvent('__ds_res_jid', {
            detail: {
                reqId: reqId,
                jid: info.jid || '',
                phone: info.phone || '',
                name: info.name || '',
                pushname: info.pushname || '',
                formattedTitle: info.formattedTitle || '',
                isBusiness: !!info.isBusiness,
                avatarUrl: info.avatarUrl || '',
                about: info.about || '',
                isGroup: !!info.isGroup
            }
        }));
    });
})();