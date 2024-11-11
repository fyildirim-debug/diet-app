// Global değişkenler
const SITE_URL = document.querySelector('meta[name="site-url"]')?.content || '';

// Sayfa yüklendiğinde çalışacak fonksiyonlar
document.addEventListener('DOMContentLoaded', function() {
    initializeTooltips();
    initializePopovers();
    initializeToasts();
    setupFormValidation();
    setupAjaxSetup();
    initializeTheme();
});

// Bootstrap tooltip'lerini aktifleştir
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Bootstrap popover'ları aktifleştir
function initializePopovers() {
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
}

// Bootstrap toast'ları aktifleştir
function initializeToasts() {
    const toastElList = [].slice.call(document.querySelectorAll('.toast'));
    toastElList.map(function(toastEl) {
        return new bootstrap.Toast(toastEl);
    });
}

// Form validasyonu
function setupFormValidation() {
    const forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
}

// Ajax ayarları
function setupAjaxSetup() {
    // jQuery ajax default ayarları
    if (typeof jQuery !== 'undefined') {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
            }
        });
    }
}

// Tema yönetimi
function initializeTheme() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);

    const themeSwitch = document.getElementById('theme-switch');
    if (themeSwitch) {
        themeSwitch.checked = savedTheme === 'dark';
    }
}

// Tema değiştirme
function toggleTheme() {
    const currentTheme = localStorage.getItem('theme') || 'light';
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';

    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
}

// Toast mesajı gösterme
function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1050';
        document.body.appendChild(container);
    }

    const toastHtml = `
        <div class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;

    const toastElement = document.createElement('div');
    toastElement.innerHTML = toastHtml;
    document.getElementById('toast-container').appendChild(toastElement.firstChild);

    const toast = new bootstrap.Toast(document.getElementById('toast-container').lastChild);
    toast.show();

    // 5 saniye sonra toast'ı kaldır
    setTimeout(() => {
        document.getElementById('toast-container').lastChild.remove();
    }, 5000);
}

// Sayfa yükleniyor göstergesi
function showLoading() {
    const loadingHtml = `
        <div id="loading-overlay" class="position-fixed w-100 h-100 d-flex justify-content-center align-items-center" style="background: rgba(0,0,0,0.5); top: 0; left: 0; z-index: 9999;">
            <div class="spinner-border text-light" role="status">
                <span class="visually-hidden">Yükleniyor...</span>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', loadingHtml);
}

function hideLoading() {
    const loadingOverlay = document.getElementById('loading-overlay');
    if (loadingOverlay) {
        loadingOverlay.remove();
    }
}

// Tarih formatla
function formatDate(date) {
    const d = new Date(date);
    return d.toLocaleDateString('tr-TR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });
}

// Sayı formatla
function formatNumber(number, decimals = 0) {
    return Number(number).toLocaleString('tr-TR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// API istekleri için yardımcı fonksiyon
async function apiRequest(endpoint, method = 'GET', data = null) {
    try {
        showLoading();

        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(SITE_URL + endpoint, options);
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.message || 'Bir hata oluştu');
        }

        return result;
    } catch (error) {
        showToast(error.message, 'danger');
        throw error;
    } finally {
        hideLoading();
    }
}

// Form verilerini JSON'a çevir
function formToJSON(form) {
    const formData = new FormData(form);
    const data = {};

    for (let [key, value] of formData.entries()) {
        if (data[key]) {
            if (!Array.isArray(data[key])) {
                data[key] = [data[key]];
            }
            data[key].push(value);
        } else {
            data[key] = value;
        }
    }

    return data;
}

// Confirm dialog
function confirmDialog(message, callback) {
    const confirmed = confirm(message);
    if (confirmed && typeof callback === 'function') {
        callback();
    }
    return confirmed;
}

// Input mask helper
function initInputMasks() {
    const phoneInputs = document.querySelectorAll('.phone-mask');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let x = e.target.value.replace(/\D/g, '').match(/(\d{0,3})(\d{0,3})(\d{0,4})/);
            e.target.value = !x[2] ? x[1] : '(' + x[1] + ') ' + x[2] + (x[3] ? '-' + x[3] : '');
        });
    });
}

// Dosya boyutu formatla
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Clipboard kopyalama
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Kopyalandı!', 'success');
    }).catch(() => {
        showToast('Kopyalama başarısız!', 'danger');
    });
}

// Debounce fonksiyonu
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Scroll to top button
window.onscroll = function() {
    const scrollTopBtn = document.getElementById("scrollTopBtn");
    if (scrollTopBtn) {
        if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
            scrollTopBtn.style.display = "block";
        } else {
            scrollTopBtn.style.display = "none";
        }
    }
};

function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// Export fonksiyonları
export {
    showToast,
    showLoading,
    hideLoading,
    formatDate,
    formatNumber,
    apiRequest,
    formToJSON,
    confirmDialog,
    copyToClipboard,
    debounce,
    scrollToTop
};