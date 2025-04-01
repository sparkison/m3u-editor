<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add HDHomeRun Tuner to Plex</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: auto;
        }
        h1, h2 {
            color: #333;
        }
        ol {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
        }
        ol li {
            padding: 5px 10px;
            margin-left: 15px;
        }
    </style>
</head>
<body>
<div class="container">
    <img src="/images/plex-logo.svg" alt="Plex Logo" width="150px" height="auto">

    <h1>How to Add "{{ $playlist->name  }}" HDHomeRun Tuner to Plex</h1>
    <p>Follow these steps to integrate your HDHomeRun device with Plex for live TV and DVR functionality.</p>

    <h2>Steps:</h2>
    <ol>
        <li>Open Plex Web App by navigating to <a href="https://app.plex.tv/" target="_blank">Plex</a> in your browser.</li>
        <li>Go to <strong>Settings</strong> (click the wrench icon in the top-right corner).</li>
        <li>Under <strong>Manage</strong>, select <strong>Live TV & DVR</strong>.</li>
        <li>Click on <strong>Set Up Plex Tuner</strong>.</li>
        <li>Enter the following HDHomeRun tuner address manually: <strong>{{ \App\Facades\PlaylistUrlFacade::getUrls($playlist)['hdhr'] }}</strong></li>
        <li>Select the tuner and click <strong>Continue</strong>.</li>
        <li>Choose your location and allow Plex to fetch channel guide data.</li>
        <ul>
            <li>If you've added an EPG and mapped it to this playlist, you can also manually add the EPG address: <strong>{{ route('epg.generate', $playlist->uuid) }}</strong></li>
        </ul>
        <li>Map available channels and finish setup.</li>
        <li>Once completed, you can access live TV and schedule recordings through Plex.</li>
    </ol>

    <p>Now your m3u editor HDHomeRun tuner is successfully integrated with Plex! 🎉</p>
</div>
</body>
</html>
