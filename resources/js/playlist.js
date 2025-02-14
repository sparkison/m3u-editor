import { parseM3U } from "@iptv/playlist";

const processPlaylist = async (url) => {
    let headers = new Headers({
        Accept: "application/json",
        "Content-Type": "application/json",
        "User-Agent":
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13",
    });
    try {
        const response = await fetch(url, {
            method: "GET",
            headers: headers,
            mode: "no-cors",
        });
        if (response.ok) {
            const content = await response.text();
            console.log("Content:", content);
            const m3u = parseM3U(content);
            console.log(m3u);
        } else {
            throw new Error(`Response status: ${response.status}`);
        }
    } catch (error) {
        console.error(error.message);
    }
};

window.processPlaylist = processPlaylist;
