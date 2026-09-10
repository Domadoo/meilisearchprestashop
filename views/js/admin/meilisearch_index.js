/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2026 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

/**
 * Page d'indexation Meilisearch (admin).
 *
 * Les réindexations passent par les endpoints AJAX « pas à pas » : on appelle start()
 * puis step() en boucle jusqu'à la fin, chaque appel renvoyant l'avancement (barre de
 * progression). Aucune requête longue, donc plus de timeout serveur ou de proxy.
 *
 * Les actions vidage/suppression restent des POST classiques (instantanés côté Meili),
 * via les formulaires cachés du template.
 */
(function () {
    'use strict';

    var cfg = window.msIndexAjax;
    if (!cfg) {
        return;
    }

    var i18n = cfg.i18n || {};
    var els = {};
    var running = false;
    var aborting = false;
    var failures = 0;

    /** Nombre d'échecs réseau consécutifs tolérés avant d'abandonner la boucle. */
    var MAX_FAILURES = 4;

    function el(id) {
        return document.getElementById(id);
    }

    /**
     * Remplace les placeholders positionnels PHP (%1$s, %2$d…) d'une chaîne traduite.
     */
    function format(template, args) {
        if (!template) {
            return '';
        }

        return template.replace(/%(\d+)\$[sd]/g, function (match, position) {
            var value = args[Number(position) - 1];

            return value === undefined ? match : String(value);
        });
    }

    function number(value) {
        return Number(value || 0).toLocaleString();
    }

    function ajax(url, method, isos) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        };

        if (method === 'POST') {
            var body = new FormData();
            // PrestaShop valide le _token porté par l'URL générée côté Twig ; on renvoie
            // aussi celui du formulaire quand il est fourni.
            if (cfg.token) {
                body.append('_token', cfg.token);
            }
            (isos || []).forEach(function (iso) {
                body.append('isos[]', iso);
            });
            options.body = body;
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (payload) {
                // Un 409 « déjà en cours » porte un message exploitable : ce n'est pas
                // une panne, on le laisse passer pour l'afficher.
                if (!response.ok && !payload.error) {
                    throw new Error('http-' + response.status);
                }

                return payload;
            }, function () {
                // Réponse non JSON (page de login après expiration de session, 502…).
                throw new Error('invalid-response');
            });
        });
    }

    // ── Rendu de la progression ─────────────────────────────────────────────────────

    function showProgress() {
        if (els.row) {
            els.row.style.display = '';
        }
    }

    function render(payload) {
        if (!els.row) {
            return;
        }

        showProgress();

        var percent = payload.percent || 0;
        if (els.bar) {
            els.bar.style.width = percent + '%';
            els.bar.setAttribute('aria-valuenow', String(percent));
            els.bar.textContent = percent + ' %';
        }

        if (els.label) {
            els.label.textContent = payload.current
                ? format(i18n.languageProgress, [
                    payload.current.name,
                    (payload.languagesDone || 0) + 1,
                    payload.languagesTotal || 1
                ])
                : '';
        }

        if (els.counter) {
            els.counter.textContent = format(i18n.productsProgress, [
                number(payload.indexed),
                number(payload.total)
            ]);
        }

        if (els.phase) {
            els.phase.textContent = payload.phaseLabel || '';
        }

        renderLog(payload.log || []);
        setBusy(!!payload.running);
    }

    function renderLog(lines) {
        if (!els.log) {
            return;
        }

        els.log.textContent = '';
        lines.forEach(function (line) {
            var item = document.createElement('li');
            item.className = line.level === 'error'
                ? 'text-danger'
                : (line.level === 'warn' ? 'text-warning' : 'text-muted');
            item.textContent = line.msg;
            els.log.appendChild(item);
        });
        els.log.scrollTop = els.log.scrollHeight;
    }

    function alertBox(type, message) {
        if (!els.alert) {
            return;
        }
        els.alert.className = 'alert alert-' + type;
        els.alert.textContent = message || '';
        els.alert.style.display = message ? '' : 'none';
    }

    /** Désactive les déclencheurs pendant un run (un seul run à la fois). */
    function setBusy(busy) {
        // L'annulation, elle, doit rester cliquable pendant tout le run.
        document.querySelectorAll('[data-ms-index-action]:not([data-ms-index-action="abort"])').forEach(function (node) {
            node.disabled = busy;
        });
        if (els.bar) {
            els.bar.classList.toggle('progress-bar-animated', busy);
        }
    }

    // ── Boucle d'indexation ─────────────────────────────────────────────────────────

    function start(isos) {
        if (running) {
            return;
        }
        running = true;
        failures = 0;
        setBusy(true);
        alertBox('info', '');

        ajax(cfg.endpoints.start, 'POST', isos).then(function (payload) {
            if (payload.error) {
                stop();
                alertBox('danger', payload.error);
                showProgress();

                return;
            }
            render(payload);
            loop();
        }).catch(onFailure);
    }

    function loop() {
        if (aborting) {
            return;
        }

        ajax(cfg.endpoints.step, 'POST').then(function (payload) {
            failures = 0;

            // Aucun run côté serveur : un start() perdu en route, ou un run annulé
            // ailleurs. Surtout ne pas l'annoncer comme une réussite.
            if (payload.idle) {
                stop();
                alertBox('danger', i18n.indexationNetworkError);

                return;
            }

            render(payload);

            if (payload.running) {
                // `waiting` = backpressure Meili ou attente de tâche : on espace les appels.
                window.setTimeout(loop, payload.waiting ? 900 : 0);

                return;
            }

            finish(payload);
        }).catch(onFailure);
    }

    function finish(payload) {
        stop();

        if (payload.errors) {
            alertBox('warning', i18n.indexationDoneErrors);

            return;
        }

        alertBox('success', i18n.indexationDone);
        // Recharge la liste pour refléter les nouveaux compteurs de documents.
        window.setTimeout(function () {
            window.location.reload();
        }, 1500);
    }

    /**
     * Échec réseau : l'état du run vit côté serveur, on réessaie quelques fois avant
     * de rendre la main (un simple rechargement de page reprend là où on en était).
     */
    function onFailure() {
        failures += 1;

        if (failures < MAX_FAILURES) {
            window.setTimeout(loop, 2000 * failures);

            return;
        }

        stop();
        alertBox('danger', i18n.indexationNetworkError);
    }

    function stop() {
        running = false;
        setBusy(false);
    }

    /** Reprise : un run laissé par un autre onglet (ou avant un rechargement) continue. */
    function resume() {
        ajax(cfg.endpoints.status, 'GET').then(function (payload) {
            if (!payload || !payload.running) {
                return;
            }
            running = true;
            failures = 0;
            render(payload);
            alertBox('info', i18n.indexationResumed);
            loop();
        }).catch(function () {
            // Pas de run récupérable : la page reste utilisable telle quelle.
        });
    }

    // ── Actions de la page ──────────────────────────────────────────────────────────

    function submitHiddenForm(formId, tokenId, action) {
        var form = el(formId);
        var token = el(tokenId);
        if (!form || !token) {
            return;
        }
        form.action = action.action;
        token.value = action.token;
        form.submit();
    }

    function checkedRows() {
        return Array.prototype.slice.call(document.querySelectorAll('.row-checkbox:checked'));
    }

    function submitBulk(formId, rows) {
        var form = el(formId);
        if (!form) {
            return;
        }
        rows.forEach(function (row) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'uids[]';
            input.value = row.value;
            form.appendChild(input);
        });
        form.submit();
    }

    function onAction(event) {
        var button = event.currentTarget;
        var action = button.getAttribute('data-ms-index-action');
        var uid = button.getAttribute('data-uid');

        switch (action) {
            case 'all':
                start([]);
                break;

            case 'language': {
                var select = el('ms-lang-select');
                var option = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
                if (!option || !option.value) {
                    window.alert(i18n.pleaseChooseLang);

                    return;
                }
                if (window.confirm(i18n.confirmReindexLang.replace('%s', option.text))) {
                    start([option.value]);
                }
                break;
            }

            case 'reindex':
                if (window.confirm(i18n.confirmReindex.replace('%s', uid))) {
                    start([button.getAttribute('data-iso')]);
                }
                break;

            case 'flush':
                if (window.confirm(i18n.confirmFlush.replace('%s', uid))) {
                    submitHiddenForm('single-flush-form', 'single-flush-token', {
                        action: button.getAttribute('data-action'),
                        token: button.getAttribute('data-token')
                    });
                }
                break;

            case 'delete':
                if (window.confirm(i18n.confirmDelete.replace('%s', uid))) {
                    submitHiddenForm('single-delete-form', 'single-delete-token', {
                        action: button.getAttribute('data-action'),
                        token: button.getAttribute('data-token')
                    });
                }
                break;

            case 'bulk':
                onBulk();
                break;

            case 'abort':
                if (window.confirm(i18n.confirmCancelIndexation)) {
                    aborting = true;
                    ajax(cfg.endpoints.abort, 'POST').then(function () {
                        window.location.reload();
                    }).catch(function () {
                        window.location.reload();
                    });
                }
                break;

            default:
                break;
        }
    }

    function onBulk() {
        var select = el('bulk-action-select');
        var rows = checkedRows();
        if (!select || !select.value || !rows.length) {
            return;
        }

        if (select.value === 'reindex') {
            var isos = rows
                .map(function (row) {
                    return row.getAttribute('data-iso');
                })
                .filter(function (iso) {
                    return !!iso;
                });

            if (isos.length && window.confirm(i18n.confirmBulkReindex.replace('%d', isos.length))) {
                start(isos);
            }

            return;
        }

        if (select.value === 'flush' && window.confirm(i18n.confirmBulkFlush.replace('%d', rows.length))) {
            submitBulk('bulk-flush-form', rows);

            return;
        }

        if (select.value === 'delete' && window.confirm(i18n.confirmBulkDelete.replace('%d', rows.length))) {
            submitBulk('bulk-delete-form', rows);
        }
    }

    function init() {
        els = {
            row: el('ms-progress-row'),
            bar: el('ms-progress-bar'),
            label: el('ms-progress-label'),
            counter: el('ms-progress-counter'),
            phase: el('ms-progress-phase'),
            log: el('ms-progress-log'),
            alert: el('ms-progress-alert')
        };

        document.querySelectorAll('[data-ms-index-action]').forEach(function (node) {
            node.addEventListener('click', onAction);
        });

        var selectAll = el('select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.row-checkbox').forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        resume();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
