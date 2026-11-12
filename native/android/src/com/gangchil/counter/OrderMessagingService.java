package com.gangchil.counter;
import android.app.*;import android.content.*;import com.google.firebase.messaging.*;
public class OrderMessagingService extends FirebaseMessagingService {
 @Override public void onNewToken(String token){getSharedPreferences("native-push",0).edit().putString("token",token).apply();}
 @Override public void onMessageReceived(RemoteMessage message){
  String user=getSharedPreferences("native-push",0).getString("user","");if(user.isEmpty()||!user.equals(message.getData().get("user_id")))return;
  String id=message.getData().get("order_id");if(id==null||!id.matches("[0-9]{1,15}"))return;
  RemoteMessage.Notification n=message.getNotification();String title=n==null?"New Gangchil order":n.getTitle(),body=n==null?"Open Orders to review the order.":n.getBody();
  Intent open=new Intent(this,MainActivity.class).putExtra("open_orders",true).setFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP|Intent.FLAG_ACTIVITY_SINGLE_TOP);
  PendingIntent pending=PendingIntent.getActivity(this,id.hashCode(),open,PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);
  getSystemService(NotificationManager.class).notify("order-"+id,0,new Notification.Builder(this,"orders").setSmallIcon(android.R.drawable.ic_dialog_info).setContentTitle(title).setContentText(body).setAutoCancel(true).setVisibility(Notification.VISIBILITY_PRIVATE).setContentIntent(pending).build());
 }
}
