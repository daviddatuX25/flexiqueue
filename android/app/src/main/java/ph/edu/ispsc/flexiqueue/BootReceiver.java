package ph.edu.ispsc.flexiqueue;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.util.Log;

public class BootReceiver extends BroadcastReceiver {

    private static final String TAG = "BootReceiver";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (Intent.ACTION_BOOT_COMPLETED.equals(intent.getAction())) {
            Log.d(TAG, "Boot completed, starting FlexiQueue service");
            Intent serviceIntent = new Intent(context, PowerGuardService.class);
            serviceIntent.setAction("START");
            context.startForegroundService(serviceIntent);
        }
    }
}