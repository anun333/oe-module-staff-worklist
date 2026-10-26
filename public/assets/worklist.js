/**
 * Staff Worklist page behaviour.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */
(function () {
    'use strict';

    const cfg = window.staffWorklist;

    /**
     * Load the patient's chart in the patient tab, then optionally move that tab to `path`.
     *
     * Pages like the referral form act on whichever patient the session holds, so the
     * chart (which sets the session patient and the header) must finish loading first.
     * If the follow-up navigation never fires, the user is left on the right chart.
     */
    function openInChart(pid, path) {
        top.restoreSession();
        top.RTop.location = cfg.webroot + '/interface/patient_file/summary/demographics.php?set_pid=' + encodeURIComponent(pid);
        if (!path) {
            return;
        }
        let tries = 0;
        const attach = function () {
            const frame = top.$("iframe[name='pat']");
            if (!frame.length) {
                if (++tries < 50) {
                    setTimeout(attach, 100);
                }
                return;
            }
            frame.one('load', function () {
                top.navigateTab(cfg.webroot + path, 'pat', function () {
                    top.activateTabByName('pat', true);
                });
            });
        };
        attach();
    }

    // For read-only pages. dlgopen puts the modal in the top window, where an onClosed
    // reload of this frame does not fire, so pages that change work use openWindow().
    function openDialog(url, title) {
        top.restoreSession();
        dlgopen(url, '_blank', 'modal-xl', 850, '', title, {sizeHeight: 'full'});
    }

    // Popup, the way core opens pages whose Close button calls window.close().
    // Reload when it closes so signed work drops off; fall back to a dialog if blocked.
    function openWindow(url, title) {
        top.restoreSession();
        const popup = window.open(url, '_blank', 'toolbar=0,location=0,menubar=0,scrollbars=yes,width=1100,height=800');
        if (!popup) {
            openDialog(url, title);
            return;
        }
        const timer = setInterval(function () {
            if (popup.closed) {
                clearInterval(timer);
                window.location.reload();
            }
        }, 1000);
    }

    document.addEventListener('click', function (event) {
        const chartLink = event.target.closest('.js-open-chart');
        if (chartLink) {
            event.preventDefault();
            openInChart(chartLink.dataset.pid, '');
            return;
        }

        const work = event.target.closest('.js-do-work');
        if (work) {
            const url = cfg.webroot + work.dataset.url;
            const title = work.textContent.trim();
            if (work.dataset.mode === 'window') {
                openWindow(url, title);
            } else if (work.dataset.mode === 'dialog') {
                openDialog(url, title);
            } else {
                openInChart(work.dataset.pid, work.dataset.url);
            }
            return;
        }

        const refresh = event.target.closest('.js-refresh-caregaps');
        if (refresh) {
            const label = refresh.textContent;
            refresh.disabled = true;
            refresh.textContent = cfg.text.checking + '…';
            post({action: 'refresh_caregaps'}).then(function (result) {
                if (!result.ok) {
                    alert(result.message);
                    refresh.disabled = false;
                    refresh.textContent = label;
                }
            });
            return;
        }

        const action = event.target.closest('.js-action');
        if (action) {
            const row = action.closest('tr');
            if (action.dataset.action === 'reopen') {
                post({key: row.dataset.key, action: 'reopen'}).then(function (result) {
                    if (!result.ok) {
                        alert(result.message);
                    }
                });
            } else {
                openActionDialog(row, action.dataset.action);
            }
        }
    });

    const form = document.getElementById('worklist-action-form');
    const errorBox = document.getElementById('worklist-action-error');

    // Date arithmetic on Y-m-d strings in local time (toISOString would shift to UTC).
    function addDays(isoDate, days) {
        const [y, m, d] = isoDate.split('-').map(Number);
        const date = new Date(y, m - 1, d + days);
        const pad = function (n) {
            return String(n).padStart(2, '0');
        };
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function openActionDialog(row, action) {
        form.reset();
        errorBox.classList.add('d-none');
        form.elements.key.value = row.dataset.key;
        form.elements.action.value = action;
        document.getElementById('worklist-action-title').textContent = cfg.text[action];
        document.getElementById('worklist-action-subject').textContent = row.dataset.patient + ': ' + row.dataset.title;

        form.querySelectorAll('[data-for]').forEach(function (group) {
            group.hidden = !group.dataset.for.split(' ').includes(action);
        });

        const note = form.elements.note;
        note.required = action === 'dismiss';
        document.getElementById('worklist-note-label').textContent =
            action === 'dismiss' ? cfg.text.reasonRequired : cfg.text.noteOptional;

        if (action === 'assign') {
            // Under facility restriction, offer only people who can reach this item's site
            // (the owner always can). The server enforces the same rule.
            // Likewise only people who hold the visit's sensitivity level, if it has one.
            const site = parseInt(row.dataset.site, 10);
            const sensitivity = row.dataset.sensitivity;
            Array.from(form.elements.assigned_to.options).forEach(function (option) {
                const sites = cfg.assigneeSites && option.value !== '' ? cfg.assigneeSites[option.value] : null;
                const levels = option.value !== '' ? (cfg.assigneeSensitivities[option.value] || []) : null;
                option.hidden = (Array.isArray(sites) && !sites.includes(site) && option.value !== row.dataset.owner)
                    || (sensitivity !== '' && Array.isArray(levels) && !levels.includes(sensitivity));
            });
            form.elements.assigned_to.value = row.dataset.assignee || '';
        }
        if (action === 'snooze') {
            const maxDays = parseInt(row.dataset.maxSnoozeDays, 10);
            const until = form.elements.until;
            until.min = addDays(cfg.today, 1);
            until.max = addDays(cfg.today, maxDays);
            until.value = addDays(cfg.today, Math.min(maxDays, 7));
            document.getElementById('worklist-until-help').textContent = cfg.text.snoozeMax + ' ' + maxDays + ' ' + cfg.text.days;
        }
        $('#worklist-action').modal('show');
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    /**
     * POST to the action endpoint. Always resolves to {ok, message?}; reloads the page on success.
     */
    function post(fields) {
        const body = new URLSearchParams(fields);
        body.set('csrf_token_form', form.elements.csrf_token_form.value);
        top.restoreSession();
        return fetch(cfg.actionUrl, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (response) {
                return response.json();
            })
            .catch(function () {
                return {ok: false};
            })
            .then(function (data) {
                if (data.ok) {
                    window.location.reload();
                }
                return {ok: !!data.ok, message: data.message || cfg.text.saveFailed};
            });
    }

    // Site picker: saved per user by the page when it receives ?sites=.
    const sitePicker = document.getElementById('worklist-sites');
    if (sitePicker) {
        const boxes = Array.from(sitePicker.querySelectorAll('input[type=checkbox]'));
        const applyButton = sitePicker.querySelector('button[type=submit]');
        const go = function (sites) {
            top.restoreSession();
            window.location.href = sitePicker.dataset.base + '&sites=' + encodeURIComponent(sites);
        };
        sitePicker.addEventListener('click', function (event) {
            event.stopPropagation(); // keep the dropdown open while ticking boxes
        });
        sitePicker.addEventListener('change', function () {
            applyButton.disabled = !boxes.some(function (box) {
                return box.checked;
            });
        });
        sitePicker.querySelector('.js-sites-all').addEventListener('click', function () {
            go('all');
        });
        sitePicker.addEventListener('submit', function (event) {
            event.preventDefault();
            const checked = boxes.filter(function (box) {
                return box.checked;
            });
            go(checked.length === boxes.length ? 'all' : checked.map(function (box) {
                return box.value;
            }).join(','));
        });
    }

    // Tabs with no count kept from the last 10 minutes are counted after the page loads, one at a
    // time, so a slow tab never holds up the page. The All tab's count waits for every tab's.
    const allCount = document.querySelector('#worklist-tab-all .badge');
    const tabCounts = Array.from(document.querySelectorAll('.js-tab-count'));
    function showTotal() {
        if (allCount && tabCounts.every(function (badge) {
            return badge.dataset.count !== undefined;
        })) {
            allCount.textContent = String(tabCounts.reduce(function (sum, badge) {
                return sum + Number(badge.dataset.count);
            }, 0));
        }
    }
    tabCounts.filter(function (badge) {
        return badge.dataset.source !== undefined;
    }).reduce(function (previous, badge) {
        return previous.then(function () {
            const params = new URLSearchParams(Object.assign({source: badge.dataset.source}, cfg.countFilter));
            top.restoreSession();
            return fetch(cfg.countsUrl + '?' + params.toString(), {credentials: 'same-origin'})
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data.ok) {
                        throw new Error(data.error);
                    }
                    badge.dataset.count = String(data.count);
                    badge.textContent = String(data.count);
                    showTotal();
                })
                .catch(function () {
                    badge.textContent = '?';
                    badge.title = cfg.text.countFailed;
                    if (allCount) {
                        allCount.textContent = '?';
                    }
                });
        });
    }, Promise.resolve());

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (form.elements.note.required && form.elements.note.value.trim() === '') {
                showError(cfg.text.reasonRequired);
                return;
            }
            const submitButton = document.getElementById('worklist-action-submit');
            submitButton.disabled = true;
            post(Object.fromEntries(new FormData(form))).then(function (result) {
                if (!result.ok) {
                    showError(result.message);
                    submitButton.disabled = false;
                }
            });
        });
    }
})();
