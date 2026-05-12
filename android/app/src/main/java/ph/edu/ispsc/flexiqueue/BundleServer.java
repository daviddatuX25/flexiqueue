package ph.edu.ispsc.flexiqueue;

import android.content.Context;
import android.content.res.AssetFileDescriptor;
import android.util.Log;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.ServerSocket;
import java.net.Socket;

/**
 * Enhanced HTTP server that streams the edge bundle from assets to Termux.
 */
public class BundleServer {
    private static final String TAG = "BundleServer";
    static final int DEFAULT_PORT = 18080;
    private static final int BUFFER_SIZE = 256 * 1024; // 256KB chunks
    private static final int SERVE_TIMEOUT_MS = 900_000; // 15 minutes

    private ServerSocket serverSocket;
    private Thread serverThread;
    private volatile boolean running = false;
    private final Context context;
    private int port = DEFAULT_PORT;
    private volatile boolean bundleServed = false;

    public BundleServer(Context context) {
        this.context = context;
    }

    public int start() throws IOException {
        try {
            // Bind to localhost specifically
            serverSocket = new ServerSocket(DEFAULT_PORT, 5, InetAddress.getByName("127.0.0.1"));
        } catch (IOException e) {
            serverSocket = new ServerSocket(0, 5, InetAddress.getByName("127.0.0.1"));
        }
        port = serverSocket.getLocalPort();
        Log.d(TAG, "Bundle server listening on http://127.0.0.1:" + port);

        running = true;
        serverThread = new Thread(this::serveLoop);
        serverThread.setPriority(Thread.MAX_PRIORITY);
        serverThread.start();

        return port;
    }

    private void serveLoop() {
        while (running) {
            try {
                serverSocket.setSoTimeout(5000);
                try (Socket client = serverSocket.accept()) {
                    client.setSoTimeout(30000);
                    client.setTcpNoDelay(true); // Speed up small packet transfers
                    handleClient(client);
                    bundleServed = true;
                }
            } catch (java.net.SocketTimeoutException e) {
                continue;
            } catch (IOException e) {
                if (running) Log.w(TAG, "Server socket error: " + e.getMessage());
            }
        }
    }

    private void handleClient(Socket client) {
        try (InputStream socketIn = client.getInputStream();
             OutputStream socketOut = client.getOutputStream()) {

            // 1. Read request headers
            BufferedReader reader = new BufferedReader(new InputStreamReader(socketIn));
            String firstLine = reader.readLine();
            if (firstLine == null) return;
            Log.d(TAG, "Request: " + firstLine);

            boolean expectContinue = false;
            String line;
            while ((line = reader.readLine()) != null && !line.isEmpty()) {
                if (line.toLowerCase().startsWith("expect: 100-continue")) {
                    expectContinue = true;
                }
            }

            // 2. Handle "Expect: 100-continue" if present
            if (expectContinue) {
                socketOut.write("HTTP/1.1 100 Continue\r\n\r\n".getBytes("UTF-8"));
                socketOut.flush();
            }

            // 3. Prepare response
            long length = -1;
            try {
                AssetFileDescriptor afd = context.getAssets().openFd(EdgePaths.BUNDLE_ASSET);
                length = afd.getLength();
                afd.close();
            } catch (IOException e) {
                Log.w(TAG, "Serving asset without length (compressed)");
            }

            StringBuilder header = new StringBuilder();
            header.append("HTTP/1.1 200 OK\r\n");
            header.append("Content-Type: application/octet-stream\r\n");
            if (length > 0) {
                header.append("Content-Length: ").append(length).append("\r\n");
            }
            header.append("Connection: close\r\n\r\n");

            socketOut.write(header.toString().getBytes("UTF-8"));
            socketOut.flush();

            // 4. Stream data directly from assets
            try (InputStream assetIn = context.getAssets().open(EdgePaths.BUNDLE_ASSET)) {
                byte[] buffer = new byte[BUFFER_SIZE];
                int bytesRead;
                long totalSent = 0;
                while ((bytesRead = assetIn.read(buffer)) != -1) {
                    socketOut.write(buffer, 0, bytesRead);
                    totalSent += bytesRead;
                }
                socketOut.flush();
                Log.d(TAG, "Successfully served " + totalSent + " bytes.");
            }

            // Small delay to let client finish receiving before socket close
            try { Thread.sleep(200); } catch (InterruptedException e) { /* ignore */ }

        } catch (Exception e) {
            Log.e(TAG, "Error serving client: " + e.getMessage());
        }
    }

    public void stop() {
        running = false;
        try {
            if (serverSocket != null) serverSocket.close();
        } catch (IOException e) { /* ignore */ }
        Log.d(TAG, "Bundle server stopped.");
    }

    public int getPort() { return port; }
}
