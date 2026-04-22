package ph.edu.ispsc.flexiqueue;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.provider.Settings;
import android.content.SharedPreferences;
import android.util.Log;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    private static final String TAG = "MainActivity";
    private static final String PREFS_NAME = "FlexiQueuePrefs";
    private static final String KEY_BATTERY_PROMPT_SHOWN = "battery_prompt_shown";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Start the foreground service
        Log.d(TAG, "Starting FlexiQueue PowerGuard service");
        Intent serviceIntent = new Intent(this, PowerGuardService.class);
        serviceIntent.setAction("START");
        startForegroundService(serviceIntent);

        // Register PowerGuard plugin
        registerPowerGuardPlugin();

        // Request battery optimization exemption once
        requestBatteryOptimizationExemption();
    }

    private void requestBatteryOptimizationExemption() {
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        boolean promptShown = prefs.getBoolean(KEY_BATTERY_PROMPT_SHOWN, false);

        if (!promptShown) {
            try {
                Intent intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
                intent.setData(Uri.parse("package:" + getPackageName()));
                startActivity(intent);

                SharedPreferences.Editor editor = prefs.edit();
                editor.putBoolean(KEY_BATTERY_PROMPT_SHOWN, true);
                editor.apply();
            } catch (Exception e) {
                Log.w(TAG, "Battery optimization request failed: " + e.getMessage());
            }
        }
    }

    private void registerPowerGuardPlugin() {
        registerPlugin(PowerGuardPlugin.class);
        registerPlugin(EdgeBundlePlugin.class);
    }
}