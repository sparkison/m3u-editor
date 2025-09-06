// Import styles (Support HMR)
import '../css/app.css'

// Enable websockets
import './echo'

// Import streaming libraries
import Hls from 'hls.js'
import mpegts from 'mpegts.js'

// Make streaming libraries globally available
window.Hls = Hls
window.mpegts = mpegts

// Vendor
import './vendor/qrcode'
import './vendor/epg-viewer'
import './vendor/stream-viewer'
import './vendor/multi-stream-manager'

// Fix broken images
document.addEventListener('error', event => {
    const el = event.target;
    if (el.tagName.toLowerCase() === 'img') {
        el.onerror = null;
        if (el.classList.contains('episode-placeholder')) {
            el.src = '/episode-placeholder.png';
        } else {
            el.src = '/placeholder.png';
        }
    }
}, true);

// Import SortableJS
import Sortable from 'sortablejs';

// Initialize SortableJS for channel reordering
function initChannelSortable() {
    const tbody = document.getElementById('channels-tbody');
    console.log('Initializing channel sortable...', tbody);
    if (tbody && !tbody.dataset.sortableInitialized) {
        new Sortable(tbody, {
            handle: '.cursor-move',
            animation: 150,
            onEnd: function () {
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const ids = rows.map(row => row.getAttribute('data-id'));
                fetch(window.channelReorderUrl || '/channels/reorder', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    body: JSON.stringify({ ids })
                }).then(response => {
                    if (response.ok) {
                        // Update the sort order column in the DOM
                        rows.forEach((row, idx) => {
                            // Find the correct cell for sort order (last cell)
                            const sortCell = row.querySelectorAll('td')[row.querySelectorAll('td').length - 1];
                            if (sortCell) {
                                sortCell.textContent = (idx + 1).toString();
                            }
                        });
                    }
                });
            }
        });
        tbody.dataset.sortableInitialized = '1';
    }
}

window.initChannelSortable = initChannelSortable;
