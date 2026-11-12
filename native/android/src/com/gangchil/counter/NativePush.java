package com.gangchil.counter;
import android.app.*;import android.content.*;import com.google.firebase.*;import com.google.firebase.messaging.*;import org.json.*;
public final class NativePush {
 static void initialize(Context c){
  if(!c.getSharedPreferences("counter",0).getBoolean("orderNotifications",false))return;
  String raw=c.getSharedPreferences("native-push",0).getString("config","");if(raw.isEmpty())return;
  try{JSONObject o=new JSONObject(raw);if(FirebaseApp.getApps(c).isEmpty())FirebaseApp.initializeApp(c,new FirebaseOptions.Builder().setApplicationId(o.getString("appId")).setApiKey(o.getString("apiKey")).setProjectId(o.getString("projectId")).setGcmSenderId(o.getString("messagingSenderId")).build());
   NotificationChannel channel=new NotificationChannel("orders","New orders",NotificationManager.IMPORTANCE_HIGH);channel.setLockscreenVisibility(Notification.VISIBILITY_PRIVATE);c.getSystemService(NotificationManager.class).createNotificationChannel(channel);
   FirebaseMessaging.getInstance().getToken().addOnSuccessListener(t->c.getSharedPreferences("native-push",0).edit().putString("token",t).apply());
  }catch(Exception e){c.getSharedPreferences("native-push",0).edit().putString("error","Notification configuration unavailable").apply();}
 }
 static void clear(Context c){c.getSharedPreferences("counter",0).edit().putBoolean("orderNotifications",false).apply();c.getSharedPreferences("native-push",0).edit().remove("user").remove("token").apply();try{FirebaseMessaging.getInstance().deleteToken();}catch(Exception ignored){}NotificationManager nm=c.getSystemService(NotificationManager.class);for(android.service.notification.StatusBarNotification n:nm.getActiveNotifications())if(n.getTag()!=null&&n.getTag().startsWith("order-"))nm.cancel(n.getTag(),n.getId());}
}
