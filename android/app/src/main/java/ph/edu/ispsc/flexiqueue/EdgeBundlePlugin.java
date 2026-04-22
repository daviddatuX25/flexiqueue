package ph.edu.ispsc.flexiqueue;

import android.content.Context;
import android.content.Intent;
import android.util.Log;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;

@CapacitorPlugin(name = "EdgeBundle")
public class EdgeBundlePlugin extends Plugin {

    private static final String TAG = "EdgeBundlePlugin";
    private static final String BUNDLE_ASSET = "edge-bundle.tgz";
    private static final String TARGET_DIR = "/data/data/com.termux/files/home/flexiqueue";
    private static final String MARKER_FILE = ".edge-extracted";

    @PluginMethod
    public void extractEdgeBundle(PluginCall call) {
        File marker = new File(TARGET_DIR, MARKER_FILE);

        if (marker.exists()) {
            JSObject ret = new JSObject();
            ret.put("alreadyExtracted", true);
            call.resolve(ret);
            return;
        }

        try {
            File targetDir = new File(TARGET_DIR);
            if (!targetDir.exists()) {
                targetDir.mkdirs();
            }

            // Copy bundle from assets to Termux home
            InputStream is = getContext().getAssets().open(BUNDLE_ASSET);
            File bundleFile = new File(TARGET_DIR, BUNDLE_ASSET);
            OutputStream os = new FileOutputStream(bundleFile);

            byte[] buffer = new byte[8192];
            int len;
            while ((len = is.read(buffer)) > 0) {
                os.write(buffer, 0, len);
            }
            os.close();
            is.close();

            // Extract tarball into the target directory
            Process tarProc = new ProcessBuilder(
                "tar", "-xzf", bundleFile.getAbsolutePath(), "-C", TARGET_DIR
            ).redirectErrorStream(true).start();
            int tarExitCode = tarProc.waitFor();
            if (tarExitCode != 0) {
                throw new RuntimeException("tar extraction failed with exit code " + tarExitCode);
            }

            // Clean up .tgz — no longer needed after extraction
            bundleFile.delete();

            // Mark as extracted
            marker.createNewFile();

            JSObject ret = new JSObject();
            ret.put("alreadyExtracted", false);
            ret.put("bundlePath", TARGET_DIR);
            call.resolve(ret);

        } catch (Exception e) {
            Log.e(TAG, "Failed to extract edge bundle", e);
            call.reject("EXTRACTION_FAILED", e.getMessage());
        }
    }

    @PluginMethod
    public void startEdgeStack(PluginCall call) {
        try {
            // Use setClassName + startService (NOT sendBroadcast)
            // sendBroadcast is unreliable on Android 12+
            Intent intent = new Intent();
            intent.setClassName("com.termux", "com.termux.app.RunCommandService");
            intent.setAction("com.termux.RUN_COMMAND");
            intent.putExtra("com.termux.RUN_COMMAND_PATH",
                "/data/data/com.termux/files/home/flexiqueue/scripts/edge/setup/phone/start-stack.sh");
            intent.putExtra("com.termux.RUN_COMMAND_ARGUMENTS", new String[]{});
            intent.putExtra("com.termux.RUN_COMMAND_WORKDIR",
                "/data/data/com.termux/files/home/flexiqueue");
            intent.putExtra("com.termux.RUN_COMMAND_BACKGROUND", false);
            getContext().startService(intent);

            JSObject ret = new JSObject();
            ret.put("started", true);
            call.resolve(ret);
        } catch (Exception e) {
            Log.e(TAG, "Failed to start edge stack", e);
            call.reject("START_FAILED", e.getMessage());
        }
    }

    @PluginMethod
    public void stopEdgeStack(PluginCall call) {
        try {
            Intent intent = new Intent();
            intent.setClassName("com.termux", "com.termux.app.RunCommandService");
            intent.setAction("com.termux.RUN_COMMAND");
            intent.putExtra("com.termux.RUN_COMMAND_PATH", "/usr/bin/pkill");
            intent.putExtra("com.termux.RUN_COMMAND_ARGUMENTS", new String[]{"-TERM", "nginx", "php-fpm"});
            intent.putExtra("com.termux.RUN_COMMAND_WORKDIR",
                "/data/data/com.termux/files/home/flexiqueue");
            intent.putExtra("com.termux.RUN_COMMAND_BACKGROUND", false);
            getContext().startService(intent);

            JSObject ret = new JSObject();
            ret.put("stopped", true);
            call.resolve(ret);
        } catch (Exception e) {
            Log.e(TAG, "Failed to stop edge stack", e);
            call.reject("STOP_FAILED", e.getMessage());
        }
    }
}