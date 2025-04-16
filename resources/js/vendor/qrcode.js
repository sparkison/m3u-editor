import QRCode from 'easyqrcodejs'

// Wait for the DOM to be fully loaded before initializing QR codes
document.addEventListener('DOMContentLoaded', function () {
    const qrEls = document.querySelectorAll('.qr-code')
    if (!qrEls.length) return
    qrEls.forEach((el) => {
        const text = el.getAttribute('data-text')
        const size = el.getAttribute('data-size') || 128
        const color = el.getAttribute('data-color') || '#ffffff'
        const bgColor = el.getAttribute('data-bg-color') || '#000000'
        new QRCode(el, {
            text,
            logo: '/logo.png',
            width: size,
            height: size,
            colorDark: bgColor,
            colorLight: color,
            logoBackgroundTransparent: false
        })
    })
})