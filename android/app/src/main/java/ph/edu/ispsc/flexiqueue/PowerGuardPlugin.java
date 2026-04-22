package ph.edu.ispsc.flexiqueue;

import android.content.Context;
import android.content.Intent;
import android.os.PowerManager;
import android.util.Log;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

@CapacitorPlugin(name = "PowerGuard")
public class PowerGuardPlugin extends Plugin {

    private static final String TAG = "PowerGuardPlugin";
    private PowerManager.WakeLock wakeLock;

    @PluginMethod
    public void startGuard(PluginCall call) {
        try {
            PowerManager pm = (PowerManager) getContext().getSystemService(Context.POWER_SERVICE);
            wakeLock = pm.newWakeLock(
                PowerManager.PARTIAL_WAKE_LOCK,
                "FlexiQueue::EdgeWakeLock"
            );
            wakeLock.setReferenceCounted(false);
            wakeLock.acquire();

            Intent serviceIntent = new Intent(getContext(), PowerGuardService.class);
            serviceIntent.setAction("START");
            getContext().startForegroundService(serviceIntent);

            JSObject ret = new JSObject();
            ret.put("guarding", true);
            call.resolve(ret);
        } catch (Exception e) {
            Log.e(TAG, "Failed to start guard", e);
            call.reject("START_FAILED", e.getMessage());
        }
    }

    @PluginMethod
    public void stopGuard(PluginCall call) {
        try {
            if (wakeLock != null && wakeLock.isHeld()) {
                wakeLock.release();
                wakeLock = null;
            }

            Intent serviceIntent = new Intent(getContext(), PowerGuardService.class);
            serviceIntent.setAction("STOP");
            getContext().startService(serviceIntent);

            JSObject ret = new JSObject();
            ret.put("guarding", false);
            call.resolve(ret);
        } catch (Exception e) {
            Log.e(TAG, "Failed to stop guard", e);
            call.reject("STOP_FAILED", e.getMessage());
        }
    }
}