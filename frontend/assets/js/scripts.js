// Archivo: frontend/assets/js/scripts.js
// Funcionalidades generales del frontend (manejo de AJAX, utilidades, etc.)

// Función de utilidad para manejar las llamadas a la API (backend PHP)
function callApi(endpoint, method = 'GET', data = null) {
    const base = typeof window !== 'undefined' && window.BASE_URL ? window.BASE_URL : '/';
    const url = base.replace(/\/$/, '') + '/api/' + endpoint; 
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
    };

    if (data) {
        options.body = JSON.stringify(data);
    }

    // Se puede agregar lógica de manejo de errores, tokens de autenticación, etc.
    return fetch(url, options)
        .then(async response => {
            const isJson = (response.headers.get('content-type') || '').includes('application/json');
            if (!response.ok) {
                const payload = isJson ? await response.json().catch(() => ({})) : {};
                const msg = payload.message || `Error HTTP: ${response.status}`;
                throw new Error(msg);
            }
            return isJson ? response.json() : { success: true };
        })
        .catch(error => {
            console.error('Error en la llamada a la API:', error);
            if (window.APP_DEBUG) {
                // Indicador visual no intrusivo
                const banner = document.createElement('div');
                banner.textContent = `API Error: ${error.message}`;
                banner.style.cssText = 'position:fixed;bottom:10px;left:10px;z-index:9999;background:#b91c1c;color:#fff;padding:8px 12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.2);font-size:12px;max-width:60vw';
                document.body.appendChild(banner);
                setTimeout(() => banner.remove(), 5000);
            }
            return { success: false, message: error.message };
        });
}

// ==== MODALES REALES (reemplazan alert/confirm/prompt nativos) ====

// Escapa HTML para insertar texto de usuario de forma segura en los modales
function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

const MODAL_ICONS = {
    success: { emoji: '✅', color: '#059669' },
    error:   { emoji: '❌', color: '#dc2626' },
    info:    { emoji: 'ℹ️', color: '#b45309' }
};

// Crea (una sola vez) y devuelve el overlay fijo de fondo semitransparente
function getModalOverlay(id) {
    let overlay = document.getElementById(id);
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = id;
        overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:1rem;';
        document.body.appendChild(overlay);
    }
    return overlay;
}

// --- showMessageModal(title, message, type) ---
// NO bloqueante. Las llamadas seguidas se encolan y se muestran de a una.
const _msgQueue = [];
let _msgModalOpen = false;

function showMessageModal(title, message, type = 'info') {
    _msgQueue.push({ title, message, type });
    if (!_msgModalOpen) _processNextMessage();
}

function _processNextMessage() {
    const next = _msgQueue.shift();
    if (!next) { _msgModalOpen = false; return; }
    _msgModalOpen = true;
    const cfg = MODAL_ICONS[next.type] || MODAL_ICONS.info;
    const overlay = getModalOverlay('app-modal-overlay');
    overlay.innerHTML = `
        <div class="bg-white rounded-lg p-6 shadow-xl max-w-md w-full text-center" role="alertdialog" aria-modal="true">
            <div style="font-size:3rem;line-height:1;margin-bottom:0.5rem;">${cfg.emoji}</div>
            <h3 style="font-weight:700;font-size:1.125rem;color:#1f2937;margin-bottom:0.5rem;">${escapeHtml(next.title)}</h3>
            <div style="color:#4b5563;font-size:0.9rem;white-space:pre-line;margin-bottom:1.25rem;">${escapeHtml(next.message)}</div>
            <button type="button" class="btn-primary px-6 py-2" data-action="ok">Aceptar</button>
        </div>`;
    const close = () => {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        _processNextMessage();
    };
    const onKey = (e) => {
        if (e.key === 'Escape') { e.preventDefault(); close(); }
    };
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay || e.target.closest('[data-action="ok"]')) close();
    });
    const okBtn = overlay.querySelector('[data-action="ok"]');
    if (okBtn) okBtn.focus();
}

// --- confirmModal(title, message, opts) => Promise<boolean> ---
// opts.danger: true => botón Confirmar en rojo; false => teal. Si se omite,
// se infiere del texto (eliminar/borrar/restablecer => destructivo).
function confirmModal(title, message, opts = {}) {
    return new Promise((resolve) => {
        const text = `${title} ${message}`;
        const danger = (opts.danger !== undefined)
            ? !!opts.danger
            : /elimin|borrar|restablecer/i.test(text);
        const overlay = getModalOverlay('app-confirm-overlay');
        const confirmStyle = danger
            ? 'background:#dc2626;color:#fff;'
            : 'background:#0d9488;color:#fff;';
        overlay.innerHTML = `
            <div class="bg-white rounded-lg p-6 shadow-xl max-w-md w-full" role="dialog" aria-modal="true">
                <h3 style="font-weight:700;font-size:1.125rem;color:#1f2937;margin-bottom:0.5rem;">${escapeHtml(title)}</h3>
                <div style="color:#4b5563;font-size:0.9rem;white-space:pre-line;margin-bottom:1.5rem;">${escapeHtml(message)}</div>
                <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
                    <button type="button" data-action="cancel" style="padding:0.5rem 1rem;border-radius:0.375rem;background:#6b7280;color:#fff;border:none;cursor:pointer;font-size:0.9rem;">Cancelar</button>
                    <button type="button" data-action="confirm" style="padding:0.5rem 1rem;border-radius:0.375rem;border:none;cursor:pointer;font-size:0.9rem;${confirmStyle}">Confirmar</button>
                </div>
            </div>`;
        let settled = false;
        const done = (val) => {
            if (settled) return;
            settled = true;
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            resolve(val);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); done(false); }
        };
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) { done(false); return; }
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            if (btn.dataset.action === 'confirm') done(true);
            else if (btn.dataset.action === 'cancel') done(false);
        });
        const confirmBtn = overlay.querySelector('[data-action="confirm"]');
        if (confirmBtn) confirmBtn.focus();
    });
}

// --- openChangePasswordModal() ---
// Modal con tres inputs tipo password (actual, nueva, confirmar) que hace el
// POST a change-password y muestra el resultado con showMessageModal.
function openChangePasswordModal() {
    if (document.getElementById('app-changepass-overlay')) return;
    const inputStyle = 'margin-top:0.25rem;width:100%;padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:0.375rem;font-size:0.9rem;box-sizing:border-box;';
    const labelStyle = 'font-size:0.875rem;color:#374151;font-weight:500;';
    const overlay = document.createElement('div');
    overlay.id = 'app-changepass-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:1rem;';
    overlay.innerHTML = `
        <div class="bg-white rounded-lg p-6 shadow-xl max-w-md w-full" role="dialog" aria-modal="true">
            <h3 style="font-weight:700;font-size:1.125rem;color:#1f2937;margin-bottom:1rem;">🔑 Cambiar contraseña</h3>
            <div style="display:flex;flex-direction:column;gap:0.75rem;margin-bottom:1.25rem;">
                <label style="${labelStyle}">Contraseña actual
                    <input type="password" id="cp-current" autocomplete="current-password" style="${inputStyle}">
                </label>
                <label style="${labelStyle}">Nueva contraseña (mín. 8 caracteres)
                    <input type="password" id="cp-new" autocomplete="new-password" style="${inputStyle}">
                </label>
                <label style="${labelStyle}">Confirmar nueva contraseña
                    <input type="password" id="cp-confirm" autocomplete="new-password" style="${inputStyle}">
                </label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
                <button type="button" data-action="cancel" style="padding:0.5rem 1rem;border-radius:0.375rem;background:#6b7280;color:#fff;border:none;cursor:pointer;font-size:0.9rem;">Cancelar</button>
                <button type="button" data-action="save" class="btn-primary" style="padding:0.5rem 1rem;">Guardar</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    const close = () => {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
    };
    const onKey = (e) => {
        if (e.key === 'Escape') { e.preventDefault(); close(); }
    };
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close();
        else if (e.target.closest('[data-action="cancel"]')) close();
    });
    overlay.querySelector('[data-action="save"]').addEventListener('click', async () => {
        const current = overlay.querySelector('#cp-current').value;
        const newPass = overlay.querySelector('#cp-new').value;
        const confirmPass = overlay.querySelector('#cp-confirm').value;
        if (!current) { showMessageModal('Validación', 'Debes introducir tu contraseña actual.', 'info'); return; }
        if (newPass.length < 8) { showMessageModal('Validación', 'La nueva contraseña debe tener al menos 8 caracteres.', 'info'); return; }
        if (newPass !== confirmPass) { showMessageModal('Validación', 'La confirmación no coincide.', 'info'); return; }
        close();
        const res = await callApi('change-password', 'POST', {
            current_password: current,
            new_password: newPass,
            confirm_password: confirmPass
        });
        if (res.success) {
            showMessageModal('Éxito', res.message || 'Contraseña actualizada.', 'success');
        } else {
            showMessageModal('Error', res.message || 'No se pudo actualizar la contraseña.', 'error');
        }
    });
    const first = overlay.querySelector('#cp-current');
    if (first) first.focus();
}

// Función para formatear fechas y horas (útil para FECHA DE ENTREGA/RECIBIDO)
function formatDateTime(date) {
    const d = date || new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hour = String(d.getHours()).padStart(2, '0');
    const minute = String(d.getMinutes()).padStart(2, '0');
    const second = String(d.getSeconds()).padStart(2, '0');
    
    // Formato YYYY-MM-DD HH:MM:SS
    return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
}

// Exponer base_url global mínima para compatibilidad con vistas que la leen
window.base_url = (typeof window !== 'undefined' && window.BASE_URL) ? window.BASE_URL : '/';

// Botón "Regresar" global: usa el historial del navegador y si no hay, va al href del botón (home)
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-back');
    if (!btn) return;
    e.preventDefault();
    let sameOriginRef = false;
    try {
        sameOriginRef = document.referrer && new URL(document.referrer).origin === window.location.origin;
    } catch (err) {
        sameOriginRef = false;
    }
    if (window.history.length > 1 && sameOriginRef) {
        window.history.back();
    } else {
        window.location.href = btn.getAttribute('href');
    }
});
