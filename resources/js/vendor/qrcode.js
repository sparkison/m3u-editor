import QRCode from 'easyqrcodejs';

function generateQRCodes() {
    const qrEls = document.querySelectorAll('.qr-code');
    qrEls.forEach((el) => {
        // Clear any existing QR code to prevent duplication
        el.innerHTML = '';

        const text = el.getAttribute('data-text');
        const size = parseInt(el.getAttribute('data-size')) || 128;
        const color = el.getAttribute('data-color') || '#ffffff';
        const bgColor = el.getAttribute('data-bg-color') || '#000000';

        new QRCode(el, {
            text,
            logo: '/logo.png',
            width: size,
            height: size,
            colorDark: bgColor,
            colorLight: color,
            logoBackgroundTransparent: false,
        });
    });
}

// Run on initial page load
document.addEventListener('DOMContentLoaded', generateQRCodes);

// Run after Livewire SPA navigation
document.addEventListener('livewire:navigated', generateQRCodes);
