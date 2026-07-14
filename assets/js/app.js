document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('signaturePad');
    if (!canvas) return;

    var pad = new SignaturePad(canvas, {
        backgroundColor: 'rgb(255,255,255)',
        penColor: 'rgb(0,0,0)',
        minWidth: 1.5,
        maxWidth: 3,
        velocityFilterWeight: 0.7,
    });

    window._signaturePad = pad;

    function resizeCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var w = canvas.offsetWidth;
        var h = canvas.offsetHeight;

        // Save current drawing before resize
        var savedData = pad.isEmpty() ? null : pad.toData();

        canvas.width  = w * ratio;
        canvas.height = h * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();

        // Restore drawing after resize
        if (savedData && savedData.length > 0) {
            pad.fromData(savedData);
        }
    }

    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    // Prevent page scroll while signing on touch devices
    canvas.addEventListener('touchstart', function (e) { e.preventDefault(); }, { passive: false });
    canvas.addEventListener('touchmove',  function (e) { e.preventDefault(); }, { passive: false });

    var sigError = document.getElementById('sigError');
    function hideSigError() { if (sigError) sigError.style.display = 'none'; }
    function showSigError() { if (sigError) sigError.style.display = 'block'; }

    canvas.addEventListener('mousedown',  hideSigError);
    canvas.addEventListener('touchstart', hideSigError);

    var clearBtn = document.getElementById('clearSig');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () { pad.clear(); hideSigError(); });
    }

    // Disable a submit button and show a busy label to prevent double-submits
    function lockSubmit(form) {
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.innerHTML;
            btn.innerHTML = 'Küldés…';
        }
    }

    // Public visitor form – signature required
    var visitorForm = document.getElementById('declarationForm');
    if (visitorForm) {
        visitorForm.addEventListener('submit', function (e) {
            if (pad.isEmpty()) {
                e.preventDefault();
                showSigError();
                return false;
            }
            hideSigError();
            document.getElementById('signatureData').value = pad.toDataURL('image/png');
            lockSubmit(visitorForm);
        });

        // Privacy/idle reset: clear the form if left filled-but-unsubmitted on a shared kiosk
        var idleTimer;
        var idleTimeoutMs = 60000;
        function resetIdleTimer() {
            clearTimeout(idleTimer);
            idleTimer = setTimeout(function () {
                visitorForm.reset();
                pad.clear();
                hideSigError();
                var noticeBox = document.getElementById('gdprNoticeText');
                if (noticeBox) noticeBox.style.display = 'none';
            }, idleTimeoutMs);
        }
        ['input', 'change', 'mousedown', 'touchstart'].forEach(function (evt) {
            visitorForm.addEventListener(evt, resetIdleTimer);
        });
        resetIdleTimer();
    }

    // Admin new form – signature optional, but capture if drawn
    var newForm = document.getElementById('newForm');
    if (newForm) {
        newForm.addEventListener('submit', function () {
            if (!pad.isEmpty()) {
                document.getElementById('signatureData').value = pad.toDataURL('image/png');
            }
        });
    }

    // Document signing form – signature required
    var signForm = document.getElementById('signForm');
    if (signForm) {
        signForm.addEventListener('submit', function (e) {
            if (pad.isEmpty()) {
                e.preventDefault();
                showSigError();
                return false;
            }
            hideSigError();
            document.getElementById('signatureData').value = pad.toDataURL('image/png');
            lockSubmit(signForm);
        });
    }
});
