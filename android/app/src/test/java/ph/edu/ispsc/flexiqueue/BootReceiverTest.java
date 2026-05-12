package ph.edu.ispsc.flexiqueue;

import android.content.Intent;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.RuntimeEnvironment;
import org.robolectric.Shadows;
import org.robolectric.annotation.Config;
import org.robolectric.shadows.ShadowApplication;

import static org.junit.Assert.*;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 28)
public class BootReceiverTest {

    private BootReceiver receiver;

    @Before
    public void setUp() {
        receiver = new BootReceiver();
    }

    @Test
    public void onReceive_bootCompleted_startsPowerGuardService() {
        Intent intent = new Intent(Intent.ACTION_BOOT_COMPLETED);
        receiver.onReceive(RuntimeEnvironment.application, intent);

        ShadowApplication shadowApp = Shadows.shadowOf(RuntimeEnvironment.application);
        Intent serviceIntent = shadowApp.getNextStartedService();
        assertNotNull("Should start PowerGuardService on BOOT_COMPLETED", serviceIntent);
        assertEquals(PowerGuardService.class.getName(),
            serviceIntent.getComponent().getClassName());
        assertEquals("START", serviceIntent.getAction());
    }

    @Test
    public void onReceive_otherAction_doesNotStartService() {
        Intent intent = new Intent("android.intent.action.SOME_OTHER_ACTION");
        receiver.onReceive(RuntimeEnvironment.application, intent);

        ShadowApplication shadowApp = Shadows.shadowOf(RuntimeEnvironment.application);
        assertNull("Should not start service for non-boot actions",
            shadowApp.getNextStartedService());
    }
}