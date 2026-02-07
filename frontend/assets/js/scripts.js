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

// Función placeholder para un modal de mensaje (para evitar usar alert())
function showMessageModal(title, message, type = 'info') {
    console.log(`[MODAL] ${title}: ${message} (${type})`);
    // Implementación en el Paso 7 para el login y recuperación de clave
    // Por ahora, usamos un alert simple como placeholder:
    alert(`${title}\n\n${message}`);
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
