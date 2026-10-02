// Invited administrator setup page: password generator, show/hide, copy, strength popover and form checks.
// Settings come from the JSON block #aam-setup-config (views/scripts/public/setup.phtml).
(function () {
    'use strict';

    var configElement = document.getElementById('aam-setup-config');
    var form = document.getElementById('aam-setup-form');
    var password = document.getElementById('password');
    var confirmation = document.getElementById('password_confirm');
    var username = document.getElementById('username');
    var submit = document.getElementById('aam-setup-submit');
    var generateButton = document.getElementById('password-generate');
    var showButton = document.getElementById('password-show');
    var copyButton = document.getElementById('password-copy');
    var copyStatus = document.getElementById('password-copy-status');
    if (!configElement || !form || !password || !confirmation || !submit) {
        return;
    }

    var config = JSON.parse(configElement.textContent);
    var plesk = config.plesk; // Plesk's texts: the extension's copies until Plesk's own are loaded.
    var text = config.text;
    var LEVELS = ['VeryWeak', 'Weak', 'Medium', 'Strong', 'VeryStrong'];

    function verdict(level) {
        return plesk['meter.verdict' + LEVELS[level]] || LEVELS[level];
    }

    // Plesk's own wording for levels, suggestions and buttons, in Plesk's interface language.
    fetch(config.localeUrl, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function (response) {
            return response.ok ? response.json() : null;
        })
        .then(function (data) {
            var messages = data && data['components.password'];
            if (messages && typeof messages === 'object') {
                Object.keys(messages).forEach(function (key) {
                    if (typeof messages[key] === 'string') {
                        plesk[key] = messages[key];
                    }
                });
                generateButton.querySelector('.pul-button__inner').textContent = plesk['generator.generate'];
                renderPopover();
            }
        })
        .catch(function () {});

    // --- Show/hide ---------------------------------------------------------------------------------
    // Eye icon inside the field, as in Plesk's password field: a closed eye while the password is hidden.

    function setVisible(visible) {
        password.type = visible ? 'text' : 'password';
        showButton.setAttribute('aria-label', visible ? text.hidePassword : text.showPassword);
        showButton.querySelector('use').setAttribute('href', '/ui-library/images/symbols.svg#' + (visible ? 'eye' : 'eye-closed') + ':16');
    }

    showButton.addEventListener('click', function () {
        setVisible(password.type === 'password');
    });

    // --- Generate ----------------------------------------------------------------------------------
    // Plesk's own algorithm (jsw/password-generator.js), including its longer variant for the "Stronger" policy,
    // using the browser's cryptographic random source.

    var symbolClasses = {
        upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        lower: 'abcdefghijklmnopqrstuvwxyz',
        number: '0123456789',
        special: '!@#$%^&*?_~'
    };

    function randomIndex(max) {
        var limit = Math.floor(0x100000000 / max) * max;
        var value;
        do {
            value = crypto.getRandomValues(new Uint32Array(1))[0];
        } while (value >= limit);
        return value % max;
    }

    function generatePassword() {
        var classes = ['upper', 'lower', 'lower', 'lower', 'number', 'number', 'special', 'number', 'number'];
        if (config.policy === 'Stronger') {
            classes.push('upper', 'upper', 'lower', 'lower', 'lower', 'lower', 'lower', 'lower');
        }
        for (var i = classes.length - 1; i > 0; i--) {
            var j = randomIndex(i + 1);
            var swap = classes[i];
            classes[i] = classes[j];
            classes[j] = swap;
        }
        return classes.map(function (name) {
            return symbolClasses[name].charAt(randomIndex(symbolClasses[name].length));
        }).join('');
    }

    generateButton.addEventListener('click', function () {
        var value = generatePassword();
        password.value = value;
        confirmation.value = value;
        clearServerError(confirmation);
        passwordChanged();
    });

    // --- Copy --------------------------------------------------------------------------------------

    var copyTimer = null;
    function showCopyStatus(message) {
        copyStatus.textContent = message;
        clearTimeout(copyTimer);
        copyTimer = setTimeout(function () {
            copyStatus.textContent = '';
        }, 3000);
    }

    copyButton.addEventListener('click', function () {
        if (password.value === '') {
            return;
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(password.value).then(function () {
                showCopyStatus(text.copied);
            }, function () {
                showCopyStatus(text.copyFailed);
            });
        } else {
            showCopyStatus(text.copyFailed);
        }
    });

    // --- Strength rating ---------------------------------------------------------------------------
    // Rated on the server by Plesk's own estimator (POST action=estimate to this page, authorized by the token).

    var estimate = {password: '', level: null, suggestions: []};
    // The last rating received, shown in the popover until a newer one arrives, so the popover does not fall back to
    // the requirement alone on every keystroke. The form checks use currentEstimate(), which only matches the exact value.
    var shownEstimate = null;
    var estimateTimer = null;
    var estimateRequest = null;

    function currentEstimate() {
        return estimate.password === password.value ? estimate : {password: password.value, level: null, suggestions: []};
    }

    function requestEstimate() {
        var value = password.value;
        clearTimeout(estimateTimer);
        if (value === '') {
            return;
        }
        estimateTimer = setTimeout(function () {
            if (estimateRequest) {
                estimateRequest.abort();
            }
            estimateRequest = window.AbortController ? new AbortController() : null;
            var body = new URLSearchParams();
            body.append('action', 'estimate');
            body.append('password', value);
            body.append('username', username ? username.value : '');
            fetch(config.estimateUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: body,
                signal: estimateRequest ? estimateRequest.signal : undefined
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (!data || password.value !== value) {
                    return;
                }
                estimate = {
                    password: value,
                    level: typeof data.level === 'number' ? data.level : null,
                    suggestions: Array.isArray(data.suggestions) ? data.suggestions : []
                };
                shownEstimate = estimate;
                check();
                renderPopover();
            }).catch(function () {});
        }, 250);
    }

    // --- Popover -----------------------------------------------------------------------------------
    // Plesk UI library popover markup, shown while the password field has focus. Closing it hides it until the next keystroke.

    var popover = null;
    var popoverContent = null;
    var popoverArrow = null;
    var dismissed = false;
    var SVG = 'http://www.w3.org/2000/svg';

    function element(tag, className, textContent) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (textContent !== undefined) {
            node.textContent = textContent;
        }
        return node;
    }

    function buildPopover() {
        var layer = element('div', 'pul-layer');
        popover = element('div', 'pul-popover');
        popover.id = 'password-strength-popover';
        popover.setAttribute('role', 'status');
        popover.style.position = 'absolute';
        popover.style.zIndex = '2085';
        popover.style.display = 'none';

        var inner = element('div', 'pul-popover__inner');
        inner.tabIndex = -1;

        var close = element('button', 'pul-button pul-button--ghost pul-button--empty pul-popover__close');
        close.type = 'button';
        close.setAttribute('aria-label', text.close);
        var closeInner = element('span', 'pul-button__inner');
        var icon = element('span', 'pul-icon pul-icon--size-12 pul-button__icon');
        icon.setAttribute('aria-hidden', 'true');
        var svg = document.createElementNS(SVG, 'svg');
        svg.setAttribute('focusable', 'false');
        var use = document.createElementNS(SVG, 'use');
        use.setAttribute('href', '/ui-library/images/symbols.svg#cross-mark:12');
        svg.appendChild(use);
        icon.appendChild(svg);
        closeInner.appendChild(icon);
        close.appendChild(closeInner);
        close.addEventListener('click', function () {
            dismissed = true;
            renderPopover();
            password.focus();
        });

        var contentOuter = element('div', 'pul-popover__content');
        popoverContent = element('div', 'pul-popover__content-inner');
        contentOuter.appendChild(popoverContent);
        inner.appendChild(close);
        inner.appendChild(contentOuter);
        popoverArrow = element('span', 'pul-popover__arrow');
        popover.appendChild(inner);
        popover.appendChild(popoverArrow);
        layer.appendChild(popover);
        document.body.appendChild(layer);

        // Keep the focus in the password field when the popover is clicked.
        popover.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        password.setAttribute('aria-describedby', popover.id);
    }

    // "The password strength is %%strength%%." with the level in bold capitals, like Plesk's own popover.
    function sentence(template, level) {
        var line = element('div');
        var parts = template.split('%%strength%%');
        line.appendChild(document.createTextNode(parts[0]));
        if (parts.length > 1) {
            line.appendChild(element('b', '', verdict(level).toLocaleUpperCase()));
            line.appendChild(document.createTextNode(parts.slice(1).join('')));
        }
        return line;
    }

    function intent(level) {
        if (level === null) {
            return '';
        }
        return level <= 1 ? 'danger' : (level === 2 ? 'warning' : 'success');
    }

    function renderPopover() {
        if (!popover) {
            buildPopover();
        }
        var rating = shownEstimate;
        var hasRating = password.value !== '' && rating !== null && rating.level !== null;
        var hasRequirement = config.minimumLevel !== null;
        if (document.activeElement !== password || dismissed || (!hasRating && !hasRequirement)) {
            popover.style.display = 'none';
            return;
        }

        popoverContent.textContent = '';
        if (hasRating) {
            popoverContent.appendChild(sentence(text.strengthIs, rating.level));
            var suggestions = rating.suggestions.map(function (key) {
                return plesk['suggestions.' + key] || '';
            }).filter(Boolean);
            if (suggestions.length) {
                popoverContent.appendChild(element('span', '', plesk['meter.description']));
                var list = element('ul');
                suggestions.forEach(function (suggestion) {
                    list.appendChild(element('li', '', suggestion));
                });
                popoverContent.appendChild(list);
            } else if (rating.level === LEVELS.length - 1) {
                popoverContent.appendChild(element('div', '', plesk['meter.yourPasswordIsStrong']));
            }
        }
        if (hasRequirement) {
            popoverContent.appendChild(sentence(text.required, config.minimumLevel));
        }

        popover.style.display = '';
        positionPopover(intent(hasRating ? rating.level : null));
    }

    function positionPopover(modifier) {
        var rect = password.getBoundingClientRect();
        var gap = 12;
        var intentClass = modifier ? ' pul-popover--' + modifier : '';
        popover.style.maxWidth = Math.max(rect.width, 280) + 'px';

        // Above the field when there is room, otherwise below it. Above, the popover is anchored by its bottom edge
        // (translateY(-100%)), so a change in height grows upwards and the arrow stays next to the field.
        popover.className = 'pul-popover pul-popover--placement-top-start' + intentClass;
        var height = popover.offsetHeight;
        var top = rect.top + window.pageYOffset - gap;
        var transform = 'translateY(-100%)';
        if (rect.top - height - gap < 0) {
            popover.className = 'pul-popover pul-popover--placement-bottom-start' + intentClass;
            top = rect.bottom + window.pageYOffset + gap;
            transform = '';
        }
        popover.style.top = top + 'px';
        popover.style.transform = transform;
        popover.style.left = (rect.left + window.pageXOffset) + 'px';
        popoverArrow.style.left = '16px';
    }

    password.addEventListener('focus', renderPopover);
    password.addEventListener('blur', renderPopover);
    window.addEventListener('resize', function () {
        if (popover && popover.style.display !== 'none') {
            renderPopover();
        }
    });

    // --- Form checks -------------------------------------------------------------------------------
    // Submit stays disabled while the password is empty, below the server's minimum strength, or the confirmation
    // differs. An unrated password (rating not back yet) is left to Plesk's check when the form is submitted.

    function setClientError(input, message) {
        var field = document.getElementById(input.id + '-field');
        if (!field) {
            return;
        }
        var error = document.getElementById(input.id + '-client-error');
        if (message) {
            if (!error) {
                error = element('div', 'pul-form-field__error');
                error.id = input.id + '-client-error';
                field.appendChild(error);
            }
            error.textContent = message;
        } else if (error) {
            error.parentNode.removeChild(error);
        }
        field.classList.toggle('pul-form-field--error', !!field.querySelector('.pul-form-field__error'));
    }

    // A server-side error for a field no longer applies once the field is changed.
    function clearServerError(input) {
        var error = document.getElementById(input.id + '-error');
        if (error) {
            error.parentNode.removeChild(error);
        }
    }

    function check() {
        var level = currentEstimate().level;
        var tooWeak = password.value !== '' && config.minimumLevel !== null && level !== null && level < config.minimumLevel;
        var mismatch = confirmation.value !== password.value;

        setClientError(password, tooWeak ? text.tooWeak : '');
        // Only complain about the confirmation once something was typed into it.
        setClientError(confirmation, mismatch && confirmation.value !== '' ? text.mismatch : '');

        var blocked = password.value === '' || tooWeak || mismatch;
        submit.disabled = blocked;
        submit.classList.toggle('pul-button--disabled', blocked);
        return !blocked;
    }

    function passwordChanged() {
        dismissed = false;
        if (password.value === '') {
            // Start afresh: the requirement alone until the first rating of the new password arrives.
            shownEstimate = null;
        }
        clearServerError(password);
        requestEstimate();
        check();
        renderPopover();
    }

    password.addEventListener('input', passwordChanged);
    confirmation.addEventListener('input', function () {
        clearServerError(confirmation);
        check();
    });
    if (username) {
        // The rating takes the username into account.
        username.addEventListener('input', function () {
            if (password.value !== '') {
                requestEstimate();
            }
        });
    }
    form.addEventListener('submit', function (event) {
        if (!check()) {
            event.preventDefault();
        }
    });

    check();
})();
