package ph.edu.ispsc.flexiqueue;

import android.app.Notification;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Context;
import android.content.Intent;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.RuntimeEnvironment;
import org.robolectric.Shadows;
import org.robolectric.annotation.Config;
import org.robolectric.shadows.ShadowService;

import static org.junit.Assert.*;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 28)
public class PowerGuardServiceTest {

    private static final String CHANNEL_ID = "flexiqueue_edge_channel";
    private static final int NOTIFICATION_ID = 1001;

    private PowerGuardService service;

    @Before
    public void setUp() {
        service = Robolectric.buildService(PowerGuardService.class).create().get();
    }

    @Test
    public void onCreate_createsNotificationChannel() {
        NotificationManager nm = (NotificationManager)
            RuntimeEnvironment.application.getSystemService(Context.NOTIFICATION_SERVICE);
        assertNotNull(nm.getNotificationChannel(CHANNEL_ID));
    }

    @Test
    public void onStartCommand_startAction_returnsStartSticky() {
        Intent intent = new Intent(RuntimeEnvironment.application, PowerGuardService.class);
        intent.setAction("START");
        int result = service.onStartCommand(intent, 0, 0);
        assertEquals(Service.START_STICKY, result);
    }

    @Test
    public void onStartCommand_stopAction_returnsStartNotSticky() {
        Intent intent = new Intent(RuntimeEnvironment.application, PowerGuardService.class);
        intent.setAction("STOP");
        int result = service.onStartCommand(intent, 0, 0);
        assertEquals(Service.START_NOT_STICKY, result);
    }

    @Test
    public void onBind_returnsNull() {
        assertNull(service.onBind(new Intent()));
    }

    @Test
    public void onStartCommand_startAction_postsForegroundNotificationWithCorrectContent() {
        Intent intent = new Intent(RuntimeEnvironment.application, PowerGuardService.class);
        intent.setAction("START");
        service.onStartCommand(intent, 0, 0);

        ShadowService shadowService = Shadows.shadowOf(service);

        // Verify notification ID
        assertEquals("Notification ID must be " + NOTIFICATION_ID,
            NOTIFICATION_ID, shadowService.getLastForegroundNotificationId());

        Notification notification = shadowService.getLastForegroundNotification();
        assertNotNull("Should post a foreground notification", notification);

        // Verify notification content
        assertNotNull("Notification extras must be populated", notification.extras);
        CharSequence title = notification.extras.getCharSequence(Notification.EXTRA_TITLE);
        CharSequence text = notification.extras.getCharSequence(Notification.EXTRA_TEXT);
        assertNotNull("Notification title must be set", title);
        assertNotNull("Notification text must be set", text);
        assertEquals("FlexiQueue Edge", title.toString());
        assertEquals("Edge device running", text.toString());

        // Verify notification is ongoing (non-dismissible)
        assertTrue("Notification must be ongoing",
            (notification.flags & Notification.FLAG_ONGOING_EVENT) != 0);
    }
}
